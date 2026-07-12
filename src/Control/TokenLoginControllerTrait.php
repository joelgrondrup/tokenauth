<?php

namespace Joelgrondrup\Tokenauth\Control;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Joelgrondrup\Tokenauth\Model\DeviceToken;
use Joelgrondrup\Tokenauth\Model\PairingToken;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\IdentityStore;
use SilverStripe\Security\Security;
use SilverStripe\View\Requirements;

/**
 * The complete token-login flow, as a trait so it can be attached to whatever
 * controller base a project prefers.
 *
 *  - The bundled {@see \Joelgrondrup\Tokenauth\Controllers\MobileLoginPageController}
 *    uses it on a plain Controller, so the module works with no setup.
 *  - To have the QR page inherit your theme's CSS/JS (registered in your
 *    project's PageController::init()), make your own controller that extends
 *    that PageController and `use`s this trait, then point the route at it:
 *
 *        class MobileLoginController extends \PageController
 *        {
 *            use \Joelgrondrup\Tokenauth\Control\TokenLoginControllerTrait;
 *        }
 *
 *  Routes:
 *   GET  mobilelogin            The QR page (requires a logged-in member).
 *   GET  mobilelogin/qrcode     JSON: a fresh pairing QR for the current member.
 *   GET  mobilelogin/status     JSON: has a given pairing token been claimed yet?
 *   GET  mobilelogin/pair       App exchanges a pairing token for a device token.
 *   POST mobilelogin/login      App presents a device token to open a session.
 *   POST mobilelogin/signout    End the current session.
 *   POST mobilelogin/revoke     App forgets (deletes) its device token.
 */
trait TokenLoginControllerTrait
{
    /**
     * Matches the route in _config/routes.yml. Lets the theme call $Link on
     * this controller (e.g. from a Page.ss sidenav include) without tripping
     * RequestHandler's "no url_segment defined" warning.
     */
    private static $url_segment = 'mobilelogin';

    private static $allowed_actions = [
        'qrcode',
        'status',
        'pair',
        'login',
        'signout',
        'revoke',
    ];

    /**
     * Optional CORS origin for browser-based Flutter web builds. Empty = off.
     */
    private static $cors_allow_origin = '';

    /**
     * Extra CSS/JS to register on the QR page for projects that don't attach
     * this trait to their themed PageController. Paths follow the Requirements
     * API (e.g. 'app/client/dist/styles/main.css' or 'themename: css/main.css').
     */
    private static $require_css = [];

    private static $require_javascript = [];

    protected function init()
    {
        parent::init();

        foreach ((array) $this->config()->get('require_css') as $css) {
            Requirements::css($css);
        }
        foreach ((array) $this->config()->get('require_javascript') as $js) {
            Requirements::javascript($js);
        }
    }

    /**
     * The QR page. Only makes sense for an authenticated member, since the
     * pairing token it produces is bound to that member's account.
     */
    public function index(HTTPRequest $request)
    {
        if (!Security::getCurrentUser()) {
            return Security::permissionFailure($this);
        }

        return $this->renderWith(['MobileLogin', 'Page']);
    }

    /**
     * Page title, available as $Title in the theme's Page.ss.
     */
    public function Title(): string
    {
        return _t(self::class . '.Title', 'Mobile login');
    }

    /**
     * Produce a fresh pairing token + QR image for the current member.
     */
    public function qrcode(HTTPRequest $request)
    {
        $member = Security::getCurrentUser();
        if (!$member) {
            return $this->json(['success' => false, 'error' => 'Not authenticated'], 401);
        }

        // Opportunistic, version-agnostic cleanup. This runs only when the QR
        // page mints a token (a low-frequency, member-triggered action - not the
        // fast status poll), so it needs no cron and no BuildTask.
        PairingToken::purgeExpired();
        DeviceToken::purgeExpired();

        $raw = PairingToken::generate($member);
        $pairing = PairingToken::findValid($raw);

        $qrUrl = Director::absoluteURL('mobilelogin/pair') . '?token=' . $raw;

        return $this->json([
            'success'    => true,
            'id'         => $pairing ? (int) $pairing->ID : null,
            'image'      => $this->buildQrDataUri($qrUrl),
            'expires_in' => (int) PairingToken::config()->get('lifetime'),
        ]);
    }

    /**
     * Poll endpoint for the desktop page: reports whether the pairing token
     * with the given ID (owned by the current member) has been claimed.
     */
    public function status(HTTPRequest $request)
    {
        $member = Security::getCurrentUser();
        if (!$member) {
            return $this->json(['success' => false, 'error' => 'Not authenticated'], 401);
        }

        $id = (int) $request->getVar('id');
        $pairing = PairingToken::get()->byID($id);

        if (!$pairing || (int) $pairing->MemberID !== (int) $member->ID) {
            // Unknown / not ours: most likely consumed then purged -> treat as expired.
            return $this->json(['success' => true, 'paired' => false, 'expired' => true]);
        }

        return $this->json([
            'success' => true,
            'paired'  => (bool) $pairing->Used,
            'expired' => !$pairing->isValid() && !$pairing->Used,
        ]);
    }

    /**
     * The mobile app calls this with the raw pairing token scanned from the QR.
     * On success it receives a long-lived device token to store on the device.
     */
    public function pair(HTTPRequest $request)
    {
        $raw = $request->getVar('token') ?: $request->postVar('token');
        if (!$raw) {
            return $this->json(['success' => false, 'error' => 'Missing token'], 400);
        }

        $pairing = PairingToken::findValid((string) $raw);
        if (!$pairing) {
            return $this->json(['success' => false, 'error' => 'Invalid or expired token'], 400);
        }

        $member = $pairing->Member();
        if (!$member || !$member->exists()) {
            return $this->json(['success' => false, 'error' => 'Token has no member'], 400);
        }

        // One-time use: mark claimed so the desktop can detect it, then issue
        // the device token.
        $pairing->Used = true;
        $pairing->write();

        $deviceInfo = (string) ($request->getHeader('User-Agent')
            ?: $request->postVar('device_info')
            ?: 'Unknown device');

        $deviceRaw = DeviceToken::issue($member, $deviceInfo);

        return $this->json([
            'success'      => true,
            'device_token' => $deviceRaw,
            'expires_in'   => (int) DeviceToken::config()->get('lifetime'),
            'member'       => $this->memberPayload($member),
        ]);
    }

    /**
     * The app presents its stored device token (POST body "device_token" or the
     * X-Device-Token header) to establish a real SilverStripe session.
     */
    public function login(HTTPRequest $request)
    {
        if (!$request->isPOST()) {
            return $this->json(['success' => false, 'error' => 'POST required'], 405);
        }

        $raw = $request->getHeader('X-Device-Token') ?: $request->postVar('device_token');
        if (!$raw) {
            return $this->json(['success' => false, 'error' => 'Missing device token'], 400);
        }

        $device = DeviceToken::findValid((string) $raw);
        if (!$device) {
            return $this->json(['success' => false, 'error' => 'Invalid or expired device token'], 401);
        }

        $member = $device->Member();
        if (!$member || !$member->exists()) {
            return $this->json(['success' => false, 'error' => 'Token has no member'], 401);
        }

        // Log in through SilverStripe's own security system: this sets the
        // session + cookies on the response the app receives.
        Injector::inst()->get(IdentityStore::class)->logIn($member, false, $request);
        $device->recordUse();

        return $this->json([
            'success'    => true,
            'expires_in' => (int) DeviceToken::config()->get('lifetime'),
            'member'     => $this->memberPayload($member),
        ]);
    }

    /**
     * End the current session. Named 'signout' (not 'logout') so this trait can
     * be applied to a project PageController that already defines logout().
     */
    public function signout(HTTPRequest $request)
    {
        Injector::inst()->get(IdentityStore::class)->logOut($request);
        return $this->json(['success' => true]);
    }

    /**
     * The app asks the server to forget a device token entirely.
     */
    public function revoke(HTTPRequest $request)
    {
        $raw = $request->getHeader('X-Device-Token') ?: $request->postVar('device_token');
        if (!$raw) {
            return $this->json(['success' => false, 'error' => 'Missing device token'], 400);
        }

        $device = DeviceToken::get()->filter('TokenHash', hash('sha256', (string) $raw))->first();
        if ($device) {
            $device->delete();
        }

        return $this->json(['success' => true]);
    }

    /**
     * Consistent JSON response with (optional) CORS headers for browser-based
     * Flutter web builds.
     */
    protected function json($data, int $code = 200): HTTPResponse
    {
        $response = HTTPResponse::create(json_encode($data), $code)
            ->addHeader('Content-Type', 'application/json');

        $origin = (string) $this->config()->get('cors_allow_origin');
        if ($origin !== '') {
            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Headers', 'Content-Type, X-Device-Token');
            $response->addHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        }

        return $response;
    }

    protected function memberPayload($member): array
    {
        return [
            'id'         => (int) $member->ID,
            'first_name' => $member->FirstName,
            'surname'    => $member->Surname,
            'email'      => $member->Email,
            'locale'     => $member->Locale,
        ];
    }

    protected function buildQrDataUri(string $data): string
    {
        $builder = new Builder(
            writer: new PngWriter(),
            writerOptions: [],
            validateResult: false,
            data: $data,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 300,
            margin: 10,
            roundBlockSizeMode: RoundBlockSizeMode::Margin
        );

        return $builder->build()->getDataUri();
    }
}
