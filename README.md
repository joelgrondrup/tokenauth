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
```

## HTTP API (for the app)

All responses are JSON with a `success` boolean.

| Method | Path                        | Auth                | Purpose |
|--------|-----------------------------|---------------------|---------|
| GET    | `/mobilelogin`              | session (member)    | The QR page. |
| GET    | `/mobilelogin/qrcode`       | session (member)    | `{ image, id, expires_in }` — a fresh pairing QR (used by the page via AJAX). |
| GET    | `/mobilelogin/status?id=`   | session (member)    | `{ paired, expired }` — poll whether a pairing token was claimed. |
| GET    | `/mobilelogin/pair?token=`  | pairing token       | Exchange a pairing token for a device token. |
| POST   | `/mobilelogin/login`        | device token        | Open a SilverStripe session. Token via `device_token` field or `X-Device-Token` header. |
| POST   | `/mobilelogin/logout`       | session             | End the current session. |
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
