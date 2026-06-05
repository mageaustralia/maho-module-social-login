# Passwordless SMS + Email OTP login (design)

**Status:** Approved (design)
**Date:** 2026-06-05
**Module:** MageAustralia_SocialLogin (extends the existing social-login module)

## Goal

Add passwordless one-time-code (OTP) authentication to the store, layered onto
the existing social-login module. Email is the account identity; SMS is an
optional delivery channel (via Clickatell) for customers with a verified mobile.
Passwords keep working - OTP is an additional option, not a replacement.

## Decisions (locked during brainstorm)

| Decision | Choice |
|---|---|
| Flows in scope | Passwordless login, new registration, social link-account, add/verify mobile |
| Storefronts | Both: Maho-rendered frontend (controllers + phtml/JS) AND headless JSON API (extend the module Api layer), sharing one OTP core |
| Identity + channel | Customer always enters EMAIL (the account key). SMS offered only when a verified mobile is on file; email is the universal fallback |
| Passwords | Additive - existing password login keeps working; OTP is an extra option |
| OTP storage | Dedicated DB table (durable; enables rate-limit + audit) |
| Mobile storage | Dedicated verified customer attributes (`mobile` + `mobile_verified`) |
| SMS provider | Clickatell REST, one-way SMS (account already exists) |

## Architecture

A stateless OTP core service + a DB-backed OTP store + pluggable delivery
channels, consumed by thin flow handlers on both frontends. Every flow reduces
to `requestCode -> verifyCode -> {loginById | createCustomer | linkIdentity |
setMobile}`.

```
                          +------------------------+
 Maho controller actions  |                        |  email channel (transactional email)
 (OtpController)  ------>  |   Otp core service     | ----> SMS channel (Clickatell REST)
 Headless API endpoints    |  (Helper/Otp)          |
 (Api/Resource)   ------>  |  request / verify      | ----> sociallogin_otp table (hashed codes)
                          +------------------------+
```

## Components

| File | Responsibility | Depends on |
|---|---|---|
| `Helper/Otp.php` | `requestCode(identifier, purpose, channel, ctx): array` and `verifyCode(identifier, purpose, code, ctx): array` - generate, hash, store, send; verify (single-use, attempt-cap, expiry, constant-time); enforce rate-limit; enumeration-safe responses | Model/Otp, channels |
| `Model/Otp.php` + `Model/Resource/Otp(/Collection)` | Active-record over `sociallogin_otp` | - |
| `Model/Otp/ChannelInterface.php` | `send(string $to, string $code, string $purpose): bool` | - |
| `Model/Otp/Channel/Email.php` | Sends the code via a transactional email template | core/email |
| `Model/Otp/Channel/Sms.php` | Sends the code via Clickatell REST (key/sender from config); never throws out | Helper/Data (config) |
| `controllers/OtpController.php` | Maho frontend actions: `requestCode`, `verify` (login), `register`, `link`, `addMobile`, `verifyMobile`. Logs in via `Mage::getSingleton('customer/session')->loginById()` | Helper/Otp |
| `Api/Resource/Otp.php` (+ processor) | Headless JSON endpoints mirroring the same flows, calling the same Otp service | Helper/Otp |
| `Block/Otp/Form.php` + `template/.../otp/*.phtml` + `skin .../otp.js` | The passwordless login form, OTP entry, and the link-account replacement | - |
| customer EAV attrs `mobile`, `mobile_verified` | Verified-mobile storage (setup script) | - |
| `etc/system.xml` additions | enable, code length, expiry minutes, max attempts, rate-limit window + per-identifier + per-IP counts, Clickatell api key + sender, SMS enabled | - |
| `sql/.../upgrade` | Create `sociallogin_otp`; add the two customer attributes | - |

Each unit is independently testable: the Otp service is pure (identifier ->
result), channels are swappable behind the interface, and the controllers / API
resources are thin adapters that translate transport <-> the service.

## Data model

`sociallogin_otp` (portable DDL - MySQL/PostgreSQL/SQLite):

| Column | Type | Notes |
|---|---|---|
| otp_id | pk | |
| identifier | varchar | normalised email (lowercased) or E.164 mobile |
| purpose | varchar | `login` / `register` / `link` / `add_mobile` |
| channel | varchar | `email` / `sms` |
| code_hash | varchar | SHA-256 of (code + server pepper); never store the plain code |
| expires_at | datetime | now + configured expiry (default 10 min) |
| attempts | smallint | verify attempts used |
| consumed_at | datetime null | set on first successful verify (single-use) |
| request_ip | varchar | for per-IP rate limiting |
| created_at | datetime | for rate-limit windows + cleanup |

Indexes: (identifier, purpose), (created_at), (request_ip, created_at).

Customer attributes: `mobile` (varchar, E.164) and `mobile_verified`
(datetime null - set when verified, cleared when the number changes).

## The four flows

1. **Login** - enter email -> `requestCode(email, 'login', channel)` (channel = sms
   only if a verified mobile exists and the customer chose it, else email) ->
   if the account exists a code is sent (otherwise nothing, but the response is
   identical) -> enter code -> `verifyCode` -> `loginById(customerId)`.
2. **Register** - enter email (+ optional mobile) -> `requestCode(email,
   'register')` -> verify -> create the customer with NO password -> login.
   Optional immediate add-mobile follow-up.
3. **Social link-account** - after OAuth returns an email that matches an existing
   customer, instead of prompting for the password -> `requestCode(email, 'link')`
   -> verify -> link the social identity to the customer -> login.
4. **Add / verify mobile** - a logged-in customer enters a mobile ->
   `requestCode(mobile, 'add_mobile', 'sms')` -> verify -> set `mobile` +
   `mobile_verified`; email a notification that the mobile changed.

## Security model

- **Hashed at rest**: store only SHA-256(code + server pepper); compare in
  constant time. Plain code exists only in transit.
- **Short-lived**: expiry from config (default 10 min, allowed 5-10).
- **Single-use**: `consumed_at` set on first success; reject consumed/expired.
- **Attempt-cap**: max verify attempts per code (default 5) then invalidate.
- **Rate-limit**: per identifier (default 3 / 5 min) AND per IP (default 15 / hr),
  counted from the table. Exceeding returns a generic throttle response.
- **Enumeration-safe**: login/link always return the same "if an account exists
  we have sent a code" message with uniform timing, regardless of whether the
  email exists. Registration distinguishes existing-account carefully (offer
  login instead) without confirming via timing.
- **SIM-swap aware**: SMS is only offered for a *verified* mobile; changing the
  mobile re-verifies and emails a change notification to the account email.
- **No secrets in code**: Clickatell key + the server pepper come from config
  (encrypted backend model), never committed.

## Clickatell integration

A thin one-way SMS channel calling Clickatell's REST send endpoint with the
configured API key and sender id. Network/credential failures are caught and
logged; the flow falls back to email (for login) or surfaces a generic error
(for add-mobile) and never blocks or throws out of the request.

## Error handling

- Channel send failure: logged to `var/log/sociallogin_otp.log`; never fatal.
- Verify failures (wrong/expired/consumed/throttled): generic, non-enumerating
  messages; increment attempts; lock the code after the cap.
- All controller/API actions validate + normalise input (email lowercased,
  mobile parsed to E.164) before touching the service.

## Testing

Dev harness (CLI, bootstrap vendor/autoload.php):
1. Happy path: request -> verify -> success; the stored code is hashed (plain not
   present).
2. Expiry: a code past `expires_at` fails.
3. Single-use: a consumed code fails on reuse.
4. Attempt-cap: N+1 wrong attempts invalidate the code.
5. Rate-limit: over-limit requests per identifier and per IP are throttled.
6. Enumeration-safe: request for a non-existent email returns the identical
   response (and sends nothing).
7. `loginById` logs the customer in after a verified login code.
8. SMS channel: a Clickatell failure is caught and does not throw.
9. Both entry points: the Maho controller action and the API resource exercise
   the same service and produce equivalent results.

## Out of scope (v1)

- Phone-number-as-login (entering a mobile instead of email to identify the
  account) - email stays the sole identity.
- OTP as a second factor on top of passwords (2FA) - this is passwordless, not
  step-up auth.
- WhatsApp / other SMS providers beyond Clickatell.
