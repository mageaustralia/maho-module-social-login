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

## License

BSD-2-Clause — same as other MageAustralia modules.
