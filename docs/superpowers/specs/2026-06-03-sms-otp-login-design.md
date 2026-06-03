# Passwordless SMS + Email OTP Login - Design Spec

- **Date:** 2026-06-03
- **Status:** Draft for review (no implementation yet - auth/security feature, wants sign-off)
- **Home:** layered into `MageAustralia_SocialLogin` (it already owns login + account linking)
- **Target:** Maho 26.x+ / PHP 8.3+

## Problem

We want a Shopify/Stripe-style passwordless login: the customer enters their
email (or phone), receives a one-time code, enters it, and is logged in - no
password to remember. This is especially valuable for the social-login
account-linking prompt, where returning social-first customers often have no
password set, yet today we force them to "enter your password to link it".

We already have a Clickatell account for SMS. The open worry was "not everyone
has a mobile number". This design resolves that directly.

## The key principle (how Shopify/Stripe handle "no mobile")

**Email is the account identity; SMS is just one optional delivery channel for
the code.** Neither Shopify nor Stripe makes the phone the identifier:

- Shopify new customer accounts: fully passwordless, 6-digit OTP to email or
  phone; email is canonical, phone optional.
- Stripe Link: email is the account, SMS OTP verifies it, email OTP is the
  fallback when there is no phone.

So the universal path is **email OTP** (every customer has an email - Maho
customers are keyed by email). **SMS OTP is an upgrade** offered only when a
mobile is on file and the customer chooses it. Nobody is ever forced phone-only.
This fits Maho's data model perfectly: phone becomes an optional OTP channel
attribute, not a new identity.

## Goals (V1)

1. **Passwordless login** by email OTP (universal) or SMS OTP via Clickatell
   (when a mobile is on file).
2. **Account creation** for a new email via the same OTP flow.
3. **Replace the social-login link prompt's password step** with a send-a-code
   verify-and-link flow.
4. **Robust security** (hashed codes, expiry, single-use, rate limiting,
   attempt caps, enumeration-safe responses).

## Non-goals (V1)

- Replacing password login entirely (passwords stay as an option).
- TOTP/authenticator apps, WebAuthn/passkeys (future).
- Using SMS as a sole second factor for sensitive admin actions (SIM-swap risk).
- Phone as an account identifier or login username.

## Architecture

Layered into `MageAustralia_SocialLogin`:

- **Frontend entry points:** a "Email me a code" / "Text me a code" option on the
  login + register forms, and the same on the social link-account prompt.
- **Controller actions** (front, CSRF-protected, rate-limited):
  - `request` - accept an email (or phone), generate + deliver an OTP.
  - `verify` - accept identifier + code, validate, then log in / create / link.
- **OTP service (Helper):** generate, hash, persist, rate-limit, verify, consume.
- **Delivery:** email via Maho transactional template; SMS via a thin Clickatell
  sender helper. Channel chosen per request (email default; SMS only when a
  verified/known mobile is on file and selected).
- **Login/link glue:** on successful verify, reuse SocialLogin's existing
  customer login + link routines.

## Data model

```
mageaustralia_sociallogin_otp
  otp_id        PK
  identifier    varchar   -- normalised email (lowercased) or E.164 phone
  channel       enum/varchar('email'|'sms')
  code_hash     varchar   -- password_hash of the 6-digit code (never store plaintext)
  purpose       varchar   -- 'login' | 'link' | 'register'
  attempts      int       default 0
  max_attempts  int       default 5
  expires_at    datetime  -- 5-10 min TTL
  consumed_at   datetime  null
  ip            varchar   -- for rate-limit + abuse audit
  created_at    datetime
  INDEX(identifier), INDEX(expires_at)
```

Portable SQL (MySQL/PostgreSQL/SQLite), Varien DDL.

## Flow

**Request a code**
1. Normalise the identifier (email lowercased, or phone to E.164).
2. Rate-limit: reject if too many requests for this identifier OR this IP in the
   window (e.g. > 3 in 15 min). Always return the SAME generic response
   regardless (enumeration-safe).
3. Generate a 6-digit code, `password_hash` it, store a row with TTL + purpose +
   ip; delete/expire any prior unconsumed rows for that identifier+purpose.
4. Deliver: email OTP (universal) or, if channel=sms and a known mobile is on
   file, send via Clickatell. Never send SMS to an arbitrary unverified number
   supplied at login (anti-toll-fraud) - SMS only to a number already on the
   customer record.
5. Respond "If an account exists / once we have your details, we have sent a
   code" - never confirm whether the email/phone is registered.

**Verify a code**
1. Load the most recent unconsumed, unexpired row for identifier+purpose.
2. Increment attempts; if attempts > max_attempts, consume/lock the row and fail.
3. `password_verify` the submitted code against `code_hash`. On mismatch, fail
   (generic message), leave the row (attempts already incremented).
4. On match: mark consumed, then:
   - `login`: log the existing customer in (or, if no account, branch to register).
   - `register`: create the customer (email verified by the OTP), log in.
   - `link`: attach the pending social identity to the customer and log in -
     this replaces the password step on the current link-account prompt.

## Clickatell SMS

A small sender helper wrapping Clickatell's one-API REST send (API key + sender
id from config). Because SMS costs money and is the weaker channel:
- **Email OTP is the default**; SMS is opt-in and only to a known on-file mobile.
- Log every send (channel, identifier hash, result) for cost + abuse audit.
- Per-identifier and per-IP send caps (config) to bound spend and toll fraud.

## Security (non-negotiable)

- Codes hashed at rest (`password_hash`), never logged in plaintext.
- 5-10 min expiry, single-use (consumed on success), prior codes invalidated.
- Rate limit per identifier + per IP on both request and verify.
- Attempt cap per code (lock after N wrong tries).
- **Enumeration-safe:** identical response whether or not the account exists.
- SMS only to a number already on the customer record (no arbitrary-number sends).
- CSRF form keys on both actions; throttle behind the existing dev gate where
  relevant.
- SIM-swap aware: fine for login convenience, not for high-risk actions.

## Config (system.xml)

- Enable email OTP / enable SMS OTP (separately).
- Code length (default 6), TTL minutes (default 10), max attempts (default 5).
- Rate limits (requests per identifier/IP per window).
- Clickatell API key, sender id, SMS message template.
- Channel default (email).

## Edge cases

- No account for the email: branch to register (the OTP already proved email
  ownership) rather than leaking "no such account".
- Customer has no mobile: SMS option simply not offered; email OTP covers them.
- Multiple rapid requests: superseded by the latest; old codes invalidated.
- Clickatell send failure: fall back to email OTP and surface a neutral retry.
- Existing password login + social login: unaffected; OTP is an added path.

## Open questions for review

1. Default channel + whether SMS is offered at all initially, or email-OTP-only
   for V1 with SMS as a fast follow.
2. Whether to allow phone as a *login identifier* (look up the customer by a
   stored phone) or only as a delivery channel for an email-identified account.
3. Code length / TTL / rate-limit thresholds (security vs friction).
4. Whether the link-account prompt should default to OTP and keep password as a
   "use password instead" link, or offer both equally.
