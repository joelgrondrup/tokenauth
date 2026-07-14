# Token Auth

A SilverStripe module (compatible with **CMS 5 and CMS 6**) that lets a
companion mobile app (built here with Flutter, for iOS and Android) log a member
into SilverStripe by scanning a QR code once, then re-using a stored **device
token** to open a real SilverStripe session again and again until it expires.

## How it works

```
┌──────────────┐        1. visit /mobilelogin (logged in)         ┌──────────────┐
│  Desktop     │ ───────────────────────────────────────────────▶│  SilverStripe │
│  browser     │◀──────── QR image (contains a pairing token) ────│  site         │
└──────────────┘                                                  └──────────────┘
        │                                                                 ▲
        │ 2. user scans QR with the app                                   │
        ▼                                                                 │
┌──────────────┐  3. GET /mobilelogin/pair?token=<pairing>               │
│  Flutter app │ ────────────────────────────────────────────────────────┘
│              │◀── device_token (long-lived) + member info ──────────────
│              │
│              │  4. POST /mobilelogin/login  (X-Device-Token: <device>)
│  (stores     │ ────────────────────────────────────────────────────────▶
│   device     │◀── success + a real SilverStripe session cookie ─────────
│   token)     │
└──────────────┘     …repeat step 4 on every app launch until expiry
```

1. A logged-in member opens `/mobilelogin` and sees a QR code. The QR embeds a
   short-lived, one-time **pairing token** bound to that member.
2. The app scans the QR.
3. The app calls `pair`, which validates the pairing token (once), issues a
   long-lived **device token**, and returns it plus the member's details. The
   app stores the device token securely on the device.
4. On every launch the app calls `login` with the stored device token. The
   module logs the member in through SilverStripe's own `IdentityStore`, so the
   response carries a genuine session cookie. The device token slides its expiry
   forward on each use (configurable) and expires ~30 days after last use.

Only SHA-256 hashes of tokens are ever stored in the database.

## Installation

```bash
composer require joelgrondrup/tokenauth
vendor/bin/sake dev/build flush=1
```

The QR page is then served at `/mobilelogin`.

## Configuration

All values are optional; defaults shown. Override in your project's
`app/_config/*.yml`:

```yaml
Joelgrondrup\Tokenauth\Model\PairingToken:
  lifetime: 120            # QR pairing token lifetime, seconds

Joelgrondrup\Tokenauth\Model\DeviceToken:
  lifetime: 2592000        # device token lifetime, seconds (30 days)
  sliding_expiry: true     # extend expiry on each successful login

Joelgrondrup\Tokenauth\Controllers\MobileLoginPageController:
  cors_allow_origin: ''    # set to an origin (or '*') to enable CORS for Flutter web
  require_css: []          # theme CSS to load on the QR page (see "Styling")
  require_javascript: []   # theme JS to load on the QR page
```

## Styling / theming the QR page

The `/mobilelogin` page renders **inside your theme**. The controller renders
with `['MobileLogin', 'Page']`, so:

- your theme's `Page.ss` provides the page chrome (header, nav, footer, `<head>`),
  exactly like a normal page; and
- the module's `templates/Layout/MobileLogin.ss` supplies just the QR content
  that drops into `$Layout`.

`$Title` is available in `Page.ss` (defaults to *"Mobile login"*, translatable
via the `MobileLoginPageController.Title` i18n key).

### App store buttons

The page can show "Download on the App Store" / "Get it on Google Play" buttons.
They are driven entirely by two `.env` variables — set either (or both) and the
matching button appears; leave them out and nothing shows:

```dotenv
TOKENAUTH_IOS_APP_URL="https://apps.apple.com/app/idXXXXXXXXX"
TOKENAUTH_ANDROID_APP_URL="https://play.google.com/store/apps/details?id=your.app.id"
```

The values are exposed to the template as `$IOSAppURL` and `$AndroidAppURL` if
you want to restyle them in your own template override.

### Loading your theme's CSS / JavaScript

The page uses your theme's chrome, but the default controller extends a plain
`Controller`, so it does **not** run your project's `PageController::init()` —
which is usually where CSS/JS is registered via the `Requirements` API. That is
why an out-of-the-box page can render unstyled.

Two ways to fix it:

**1. List the assets in config (recommended — simple and robust).** Point the
page at the same files your theme loads:

```yaml
Joelgrondrup\Tokenauth\Controllers\MobileLoginPageController:
  require_css:
    - 'app/client/dist/styles/main.css'   # your theme's CSS
  require_javascript:
    - 'app/client/dist/js/main.js'
```

The controller registers these via `Requirements` on every render, so they are
injected into your `Page.ss` `<head>`/`<body>` like any normal page.

**2. Base the controller on your `PageController` (full inheritance).** If you'd
rather inherit *everything* your `PageController::init()` sets up, make your own
controller from the shipped trait and point the route at it. Create it in your
app:

```php
// app/src/Control/TokenLoginController.php
class TokenLoginController extends PageController   // your themed base controller
{
    use \Joelgrondrup\Tokenauth\Control\TokenLoginControllerTrait;
}
```

…and override the route:

```yaml
# app/_config/tokenauth-routes.yml
---
Name: app-tokenauth-routes
After: '#mobileloginroutes'
---
SilverStripe\Control\Director:
  rules:
    'mobilelogin//$Action': 'TokenLoginController'
```

> **Collision caveat.** The trait defines the actions `index`, `qrcode`,
> `status`, `pair`, `login`, `signout`, `revoke` (plus `init`, `Title`). If your
> `PageController` already defines a method with one of those names and an
> incompatible signature, PHP will fatal. (This is why the session endpoint is
> `signout`, not `logout` — most `PageController`s already have `logout()`.) For
> any remaining clash, alias the trait method in your class, e.g.
> `use TokenLoginControllerTrait { index as tokenIndex; }`, or just use option 1.

### Overriding the template

Because it uses the normal template cascade, you override it from your **app or
theme** — no module changes, nothing to fork. Templates in `app/templates/`
(and any enabled theme) take precedence over the module's.

**Restyle the QR content, keep your site chrome** — copy the module's layout
template and edit it:

```
app/templates/Layout/MobileLogin.ss          # your version wins
# (or) themes/<your-theme>/templates/Layout/MobileLogin.ss
```

Everything inside is yours — markup, CSS, the polling `<script>`. Just keep the
element IDs the script relies on (`tokenauth-qr`, `tokenauth-status`,
`tokenauth-overlay`, `tokenauth-refresh`) or adjust the script to match. The
page talks to `mobilelogin/qrcode` and `mobilelogin/status` (see the API below),
so you can rewrite the front-end however you like.

**Replace the whole page (bypass your `Page.ss` chrome)** — add a *top-level*
template (note: not under `Layout/`):

```
app/templates/MobileLogin.ss                  # becomes the full page, standalone
```

When a top-level `MobileLogin.ss` exists it is used as the entire response and
your theme's `Page.ss` is skipped — handy for a minimal, standalone login screen.

> The module intentionally ships **no** top-level `MobileLogin.ss`, only
> `Layout/MobileLogin.ss`, which is what lets your theme's `Page.ss` wrap it by
> default.

## HTTP API (for the app)

All responses are JSON with a `success` boolean.

| Method | Path                        | Auth                | Purpose |
|--------|-----------------------------|---------------------|---------|
| GET    | `/mobilelogin`              | session (member)    | The QR page. |
| GET    | `/mobilelogin/qrcode`       | session (member)    | `{ image, id, expires_in }` — a fresh pairing QR (used by the page via AJAX). |
| GET    | `/mobilelogin/status?id=`   | session (member)    | `{ paired, expired }` — poll whether a pairing token was claimed. |
| GET    | `/mobilelogin/pair?token=`  | pairing token       | Exchange a pairing token for a device token. |
| POST   | `/mobilelogin/login`        | device token        | Open a SilverStripe session. Token via `device_token` field or `X-Device-Token` header. |
| POST   | `/mobilelogin/signout`      | session             | End the current session. |
| POST   | `/mobilelogin/revoke`       | device token        | Delete (forget) a device token. |

### Example: pairing

```
GET /mobilelogin/pair?token=<raw-pairing-token>

{
  "success": true,
  "device_token": "…64 hex chars…",
  "expires_in": 2592000,
  "member": { "id": 1, "first_name": "…", "surname": "…", "email": "…", "locale": "en_US" }
}
```

### Example: login

```
POST /mobilelogin/login
X-Device-Token: <raw-device-token>

{ "success": true, "expires_in": 2592000, "member": { … } }
```

The response sets a session cookie — keep it in the app's cookie jar (or the
webview's) and send it on subsequent requests to stay authenticated. When the
device token is invalid or expired the endpoint returns `401`; the app should
then send the user back through the QR pairing flow.

## Managing & expiring tokens

- Admins can review and delete tokens in the CMS under **Tokens**
  (`Joelgrondrup\Tokenauth\ModelAdmin\TokenAdmin`).
- Expired/used rows are purged automatically (and cheaply) whenever the QR page
  mints a token, so no cron is required. Expired tokens are always rejected at
  validation time regardless, so leftover rows are harmless.
- If you want a scheduled purge as well, both models expose a version-agnostic
  `purgeExpired()` you can call from a task or cron in your own project (the
  `BuildTask` API differs between CMS 5 and CMS 6, so the module deliberately
  ships no task of its own):

  ```php
  \Joelgrondrup\Tokenauth\Model\PairingToken::purgeExpired();
  \Joelgrondrup\Tokenauth\Model\DeviceToken::purgeExpired();
  ```

## Security notes

- Tokens are high-entropy random values; only their SHA-256 hash is stored.
- Pairing tokens are one-time-use and short-lived (2 minutes by default).
- Serve the site over HTTPS so tokens are never sent in clear text.
- `login` establishes a normal SilverStripe session via `IdentityStore`, so all
  existing permission checks and member controls apply to the resulting session.
- The token `login` path deliberately does **not** run the interactive MFA flow
  (the app can't complete a TOTP challenge). The trust comes from pairing: the
  device token can only be issued from an already-authenticated desktop session
  (which itself passed MFA). Treat the device token as a possession factor and
  keep the device-token lifetime short if that trade-off matters to you.
```
