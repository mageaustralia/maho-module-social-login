# MageAustralia SMS Login

[![CI](https://github.com/mageaustralia/maho-module-social-login/actions/workflows/ci.yml/badge.svg)](https://github.com/mageaustralia/maho-module-social-login/actions/workflows/ci.yml)
[![License: OSL-3.0](https://img.shields.io/badge/license-OSL--3.0-blue.svg)](LICENSE)

SMS one-time-code login and mobile verification for Maho, plus a passwordless login block that offers core's Magic Link alongside the SMS code.

Social sign-in (Google, Apple, Facebook) moved into Maho core as `Maho_SocialLogin` in 26.9, built from this module's 1.x line as a starting point. From 2.0 this module no longer provides it.

## Which version

| Maho | Module | Provides |
|------|--------|----------|
| 26.5 – 26.7 | `^1.1` | Social sign-in + SMS login |
| 26.9 | `^2.0` | SMS login (social sign-in is in core) |

Composer picks the right one for your Maho version.

## Installation

```bash
composer require mageaustralia/maho-module-social-login
php maho migrate
```

If the package isn't on Packagist, add the repository first:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/mageaustralia/maho-module-social-login"
        }
    ]
}
```

## Upgrading from 1.x

1.x and Maho 26.9's core module share names (the `sociallogin` alias, the `sociallogin_setup` resource and the `sociallogin.xml` layout), so they cannot be installed together. Upgrade Maho and this module in the same step. 2.0 moves to its own `smslogin` alias, `smslogin_setup` resource and `smslogin.xml` layout.

What `php maho migrate` does:

- Copies every linked social account from the 1.x `mageaustralia_social_login` table into core's `social_login_identity`, so customers keep signing in with the same Google, Apple or Facebook account. Re-running it is safe.
- Leaves the 1.x table in place. Nothing reads it any more; drop it once you are satisfied.
- Keeps SMS settings, OTP codes and verified mobiles as they are: the `customer/sociallogin/*` config paths, the `sociallogin_otp` table and the `/sociallogin/otp/*` routes are unchanged.

What you need to do:

- Enter the Google, Apple and Facebook credentials again in core's settings (**System > Configuration > Customers > Social Login**, config path `customer/social_login/*`). Core does not read 1.x's settings.
- In theme templates, change the block aliases `sociallogin/passwordless` and `sociallogin/mobile` to `smslogin/passwordless` and `smslogin/mobile`. Core now owns `sociallogin/*`.
- Replace any use of 1.x's social buttons with core's; 1.x's `sociallogin/auth/*` routes and `sociallogin.js` are gone.

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

All settings live under **System > Configuration > Mage Australia > SMS Login** in the Maho admin:

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
- **Retention** - a nightly cron (`sociallogin_otp_cleanup`, 03:17) deletes rows older than 48 hours. The grace period is deliberate: the rate limiter counts recent rows per identifier and per IP, so purging too eagerly would blunt it.
- **Multi-store scope (residual)** - OTP rows are not scoped by `store_id`; the current design assumes a single-website deployment. In a multi-website install that shares (or leaves blank) the pepper, a code could be valid across websites. This is a documented limitation; per-store scoping is a future enhancement.

## License

OSL-3.0 — matches the Maho core base.
