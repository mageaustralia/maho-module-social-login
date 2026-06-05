# MageAustralia Social Login

[![CI](https://github.com/mageaustralia/maho-module-social-login/actions/workflows/ci.yml/badge.svg)](https://github.com/mageaustralia/maho-module-social-login/actions/workflows/ci.yml)
[![License: BSD-2-Clause](https://img.shields.io/badge/license-BSD--2--Clause-blue.svg)](LICENSE)

Social login for Maho — supports Google, Apple, and Facebook. Works with both the default Maho frontend and Maho Storefront (headless).

## How It Works

1. User clicks a social login button on the storefront
2. Provider popup opens, user authenticates
3. Provider returns an ID token (Google/Apple) or access token (Facebook)
4. Storefront sends the token to `POST /api/customers/social-auth`
5. Backend verifies the token with the provider, finds or creates a customer, and returns a Maho JWT
6. Storefront stores the JWT and redirects to the account page

Customer linking logic:
- If the provider account is already linked → log in as that customer
- If a customer with the same email exists → auto-link and log in (safe because providers verify email ownership)
- If no customer exists → create a new account with a random password, link, and log in

## Installation

### Via Composer (recommended)

```bash
composer require mageaustralia/maho-module-social-login
php maho migrate
```

If the package isn't on Packagist, add the repository first:

```json
// composer.json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/mageaustralia/maho-module-social-login"
        }
    ]
}
```

Then run:

```bash
composer require mageaustralia/maho-module-social-login:dev-main
php maho migrate
```

### Manual Installation

Copy the module files into your Maho installation:

```
app/etc/modules/MageAustralia_SocialLogin.xml
app/code/community/MageAustralia/SocialLogin/
```

Then run:

```bash
composer dump-autoload
php maho migrate
```

The module creates a `mageaustralia_social_login` table to store provider-to-customer links.

## Configuration

All settings are under **System > Configuration > Customers > Social Login** in the Maho admin.

---

### Google Sign-In

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a project (or select an existing one)
3. Navigate to **APIs & Services > OAuth consent screen**
   - Set User type to **External**
   - Fill in the app name, support email, and developer contact
   - Add scopes: `email`, `profile`, `openid`
   - Add your email as a test user (if staying in "Testing" mode)
   - Click **Publish App** when ready for production
4. Navigate to **APIs & Services > Credentials**
5. Click **Create Credentials > OAuth client ID**
   - Application type: **Web application**
   - Name: e.g. "Maho Storefront"
   - **Authorized JavaScript origins**: add your storefront domain(s)
     ```
     https://your-store.com
     ```
   - **Authorized redirect URIs**: add the callback URL
     ```
     https://your-store.com/social-auth/callback
     ```
6. Copy the **Client ID** (looks like `123456789-xxxx.apps.googleusercontent.com`)
7. In Maho admin: set **Google Sign-In Enabled** to Yes and paste the Client ID

The Client Secret is **not needed** — this module verifies Google ID tokens directly via Google's public JWKS keys.

---

### Apple Sign-In

1. Go to [Apple Developer Console](https://developer.apple.com/account)
2. Navigate to **Certificates, Identifiers & Profiles > Identifiers**
3. Create an **App ID** if you don't have one:
   - Enable **Sign in with Apple** capability
4. Create a **Services ID**:
   - Description: e.g. "Maho Storefront Login"
   - Identifier: e.g. `com.yourstore.auth` (this is your Service ID)
   - Enable **Sign in with Apple**
   - Click **Configure**:
     - Primary App ID: select the App ID from step 3
     - **Domains**: `your-store.com`
     - **Return URLs**: `https://your-store.com/social-auth/callback`
5. In Maho admin: set **Apple Sign-In Enabled** to Yes and paste the **Service ID** (the identifier from step 4, e.g. `com.yourstore.auth`)

Apple requires an Apple Developer Program membership ($99/year).

Note: Apple only sends the user's email on the **first** authorization. If a user revokes and re-authorizes, the email may not be included. The module handles this — if there's no email and no existing link, it asks the user to sign in with email/password first.

---

### Facebook Login

1. Go to [Meta for Developers](https://developers.facebook.com/)
2. Click **Create App** > choose **Consumer** (or **Other** > Consumer)
3. Add the **Facebook Login** product
4. Go to **Facebook Login > Settings**:
   - **Valid OAuth Redirect URIs**: add your storefront domain
     ```
     https://your-store.com/
     ```
   - Enable **Login with the JavaScript SDK**
   - **Allowed Domains for the JavaScript SDK**: `your-store.com`
5. Go to **App Settings > Basic**:
   - Copy the **App ID**
   - Copy the **App Secret** (click "Show")
6. In Maho admin: set **Facebook Login Enabled** to Yes, paste the **App ID** and **App Secret**

The App Secret is stored encrypted in the database and is used server-side to verify access tokens via Facebook's Graph API. It is never sent to the browser.

To go live: In the Meta dashboard, switch the app from **Development** to **Live** mode. You'll need to complete App Review for the `email` permission if you haven't already.

---

## Maho Storefront Integration

If you're using [Maho Storefront](https://github.com/mageaustralia/maho-storefront) (headless), the social login buttons appear automatically on the login and register pages when providers are enabled in the admin.

### How it works

1. The module's observer hooks into `api_store_config_dto_build` and injects enabled providers into the store config API response
2. When the storefront syncs config (`POST /sync/config`), the provider data is cached in Cloudflare KV
3. The Layout template conditionally loads the provider SDKs (Google GIS, Apple JS, Facebook SDK)
4. The `SocialLoginButtons` component renders branded buttons for each enabled provider
5. A `social-auth` Stimulus controller handles the popup flows and calls `POST /api/customers/social-auth`

### After enabling a provider in admin

You must re-sync the storefront config for buttons to appear:

```bash
curl -X POST https://your-storefront.com/sync/config -H "Authorization: Bearer YOUR_SYNC_SECRET"
```

Or trigger a full sync from the dev toolbar.

### API Endpoint

```
POST /api/customers/social-auth
Content-Type: application/ld+json

{
  "provider": "google",        // "google", "apple", or "facebook"
  "token": "eyJ...",           // ID token (Google/Apple) or access token (Facebook)
  "maskedId": "abc123..."      // optional: guest cart masked ID to merge
}

→ 200 OK
{
  "authToken": "eyJ...",       // Maho JWT (use as Bearer token)
  "customer": {
    "id": 42,
    "email": "user@example.com",
    "firstName": "Jane",
    "lastName": "Doe"
  },
  "cartMaskedId": "abc123...", // customer's cart (merged if guest cart provided)
  "cartItemsQty": 3,
  "isNewCustomer": false
}
```

This endpoint has `security: "true"` — it's public, no authentication required. It uses the API Platform resource-level security feature (Maho PR #578).

### Traditional Frontend

For non-headless Maho installations, the API endpoint works the same way. You would need to implement your own frontend JavaScript to handle the provider popups and call the API. The backend module is frontend-agnostic.

## Database

The module creates one table:

```sql
mageaustralia_social_login
├── entity_id       INT (PK, auto-increment)
├── customer_id     INT (FK → customer_entity, CASCADE delete)
├── provider        VARCHAR(32)  — 'google', 'apple', or 'facebook'
├── provider_id     VARCHAR(255) — unique user ID from provider ('sub' claim)
├── provider_email  VARCHAR(255) — email at time of first auth
└── created_at      TIMESTAMP
    UNIQUE(provider, provider_id)
```

## Token Verification

Each provider uses a different verification method:

| Provider | Token Type | Verification Method |
|----------|-----------|-------------------|
| Google | ID token (JWT) | JWKS public keys from `googleapis.com/oauth2/v3/certs` |
| Apple | ID token (JWT) | JWKS public keys from `appleid.apple.com/auth/keys` |
| Facebook | Access token | `debug_token` API + `graph.facebook.com/me` with App Secret |

Google and Apple tokens are verified locally using `firebase/php-jwt` (already in Maho's vendor). Facebook tokens require a server-side API call using the App Secret.

## SMS one-time-code login + Magic Link

Passwordless login in Maho comes in two complementary parts:

- **Email passwordless login is provided by Maho core (Magic Link).** Core emails a single-use sign-in link to the customer's address; the customer clicks it and is logged in, no password required. That feature lives entirely in core under `customer/login/magic_link_*` (endpoints `customer/account/magiclinkrequestpost` and `customer/account/magiclinklogin`). This module does **not** duplicate it - configure Magic Link in core if you want email passwordless login.
- **SMS one-time-code login is added by this module.** It is the SMS counterpart of Magic Link: the customer enters their email, a 6-digit code is texted to the verified mobile on file for that account, and entering the code signs them in. The module also ships the add/verify-mobile flow and a pluggable SMS provider, and integrates the SMS code form into the storefront login template.

### SMS login flow

1. On the login page the customer enters their **email**.
2. The module looks up the account for that email and its **verified mobile** number, then texts a single-use code to that mobile. The on-screen response is identical whether or not a matching account/mobile exists (enumeration-safe), so nothing is revealed to a stranger.
3. The customer types the code; on success they are logged in via `loginById`.

Endpoints (POST, JSON, form-key protected):

```
POST /sociallogin/otp/request   (email, purpose=login)  -> texts the code to the account's verified mobile
POST /sociallogin/otp/verify    (email, code)           -> verifies and signs the customer in
```

> **Requirement:** SMS login only works for an account that already has a **verified mobile** on file. A customer sets one via the add-mobile flow below. With no verified mobile, no code is sent (silently, to stay enumeration-safe), so the customer cannot complete SMS login.

### Add / verify mobile flow

A logged-in customer registers a mobile number and confirms ownership with an SMS code before it is stored as verified:

```
POST /sociallogin/otp/request     (mobile, purpose=add_mobile)  -> texts a code to that mobile
POST /sociallogin/otp/add-mobile  (mobile, code)                -> verifies and saves mobile + mobile_verified
```

On success the `mobile` and `mobile_verified` customer attributes are set, which then unlocks SMS login for that account.

### Configuration

All settings live under **System > Configuration > Customers > Social Login** in the Maho admin, alongside the social provider settings:

| Setting | Config key (`customer/sociallogin/...`) | Purpose |
|---------|------------------------------------------|---------|
| Enable SMS one-time-code login | `otp_enabled` | Master toggle for the SMS code feature |
| OTP code length | `otp_length` | Number of digits in a generated code |
| OTP expiry (minutes) | `otp_expiry_minutes` | How long a code stays valid (default 10) |
| Max verify attempts per code | `otp_max_attempts` | Attempt cap before a code is locked |
| Resend cooldown (seconds) | `otp_resend_cooldown` | Minimum interval between code requests (anti click-spam) |
| Rate limit: max requests per identifier / window | `otp_rl_identifier_count` / `otp_rl_identifier_window` | Per-identifier volume limit |
| Rate limit: max requests per IP / window | `otp_rl_ip_count` / `otp_rl_ip_window` | Per-IP volume limit |
| Enable SMS channel (Clickatell) | `otp_sms_enabled` | Gates the actual SMS send |
| SMS provider | `otp_sms_provider` | Which provider delivers the SMS |
| Clickatell API Key / Sender ID | `otp_clickatell_api_key` / `otp_clickatell_sender` | Credentials for the Clickatell provider |
| OTP server pepper (secret) | `otp_pepper` | Dedicated secret used to hash codes (see Security notes) |

The sub-fields are hidden until the master toggle is on, and the provider credential fields appear only when the SMS channel is enabled.

### Pluggable SMS providers

SMS delivery is pluggable. Clickatell is the first provider, selected via the **SMS provider** dropdown. To add another provider:

1. Create `Model/Sms/Provider/<Name>.php` implementing `Model/Sms/ProviderInterface`.
2. Add a matching entry to `Model/System/Config/Source/SmsProvider` so it appears in the dropdown.

No core changes are needed - `Helper/Sms` resolves the active provider from the dropdown selection at send time.

### Security notes

The SMS code flow is hardened, but a couple of residual limitations are documented honestly below.

- **Codes at rest** - codes are hashed (SHA-256 with a server-side pepper, single-use, short-lived; default 10 minute expiry). Only one live code exists per identifier at a time, each code is attempt-capped, the consume is a single atomic update (no double-use races), and requests are rate-limited per identifier and per IP. A resend cooldown blocks click-spam.
- **Enumeration-safe responses** - the request endpoint returns a uniform body whether or not an account (or verified mobile) exists, so the response never reveals account existence or throttling state.
- **Pepper** - a dedicated `otp_pepper` is recommended. If it is left blank the install crypt key is used instead (codes are never hashed unsalted), but a distinct pepper is stronger because it isolates OTP hashing from every other use of the crypt key.
- **Timing-based enumeration (residual)** - although the response body is uniform, a login request for an existing account with a verified mobile triggers synchronous code delivery, so response latency could still hint at whether such an account exists. This is inherent to delivering the code inline. A future enhancement could flush the response before delivery, or hand delivery to an async sender.
- **Multi-store scope (residual)** - OTP rows are not scoped by `store_id`; the current design assumes a single-website deployment. In a multi-website install that shares (or leaves blank) the pepper, a code could be valid across websites. This is a documented limitation; per-store scoping is a future enhancement.

## License

OSL-3.0 — matches the Maho core base.
