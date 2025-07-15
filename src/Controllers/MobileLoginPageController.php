<?php

use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Security\RandomGenerator;
use SilverStripe\Security\Security;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;

class MobileLoginPageController extends ContentController{

    private static $allowed_actions = [
        'pair'
    ];

    public function index (){

        return $this->renderWith(["MobileLoginPage"]);

    }

    public function pair(HTTPRequest $request){

        $token = $request->getVar('token');
        $hash = hash('sha256', $token);

        $pairingToken = PairingToken::get()->filter('TokenHash', $hash)->first();

        if (!$pairingToken || !$pairingToken->isValid()) {
            return $this->json(['error' => 'Invalid or expired token'], 400);
        }

        $member = $pairingToken->Member();

        $randomGenerator = new RandomGenerator();

        $deviceRawToken = $randomGenerator->randomToken();
        $deviceHash = hash('sha256', $deviceRawToken);

        DeviceToken::create([
            'TokenHash' => $deviceHash,
            'MemberID' => $member->ID,
            'DeviceInfo' => 'Scanned from QR on desktop',
            'LastUsed' => DBDatetime::now()
        ])->write();

        // Optional: delete pairing token after use
        $pairingToken->delete();

        return $this->json([
            'device_token' => $deviceRawToken,
            'user_id' => $member->ID,
        ]);

    }

    protected function json($data, $code = 200)
    {
        return HTTPResponse::create(json_encode($data), $code)
            ->addHeader('Content-Type', 'application/json');
    }

    public function generatepairingtoken(){

        $randomGenerator = new RandomGenerator();
        $rawToken = $randomGenerator->randomToken();

        $hash = hash('sha256', $rawToken);

        PairingToken::create([
            'TokenHash' => $hash,
            'MemberID' => Security::getCurrentUser()->ID,
            'Expires' => DBDatetime::now()->modify('+2 minutes'),
        ])->write();

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];

        $baseUrl = $scheme . '://' . $host;

        $qrUrl = $baseUrl . "/mobilelogin/pair?token=$rawToken";

        $writer = new PngWriter();

        $builder = new Builder(
            writer: new PngWriter(),
            writerOptions: [],
            validateResult: false,
            data: $qrUrl,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 300,
            margin: 10,
            roundBlockSizeMode: RoundBlockSizeMode::Margin
        );

        $result = $builder->build();

        return $result->getDataUri();

    }

}