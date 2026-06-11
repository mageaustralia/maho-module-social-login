<?php

declare(strict_types=1);

class MageAustralia_SocialLogin_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const XPATH_GOOGLE_ENABLED    = 'customer/sociallogin/google_enabled';
    public const XPATH_GOOGLE_CLIENT_ID  = 'customer/sociallogin/google_client_id';
    public const XPATH_APPLE_ENABLED      = 'customer/sociallogin/apple_enabled';
    public const XPATH_APPLE_SERVICE_ID   = 'customer/sociallogin/apple_service_id';
    public const XPATH_FACEBOOK_ENABLED   = 'customer/sociallogin/facebook_enabled';
    public const XPATH_FACEBOOK_APP_ID    = 'customer/sociallogin/facebook_app_id';
    public const XPATH_FACEBOOK_APP_SECRET = 'customer/sociallogin/facebook_app_secret';

    public function isGoogleEnabled(?int $storeId = null): bool
    {
        return (bool) Mage::getStoreConfigFlag(self::XPATH_GOOGLE_ENABLED, $storeId);
    }

    public function getGoogleClientId(?int $storeId = null): string
    {
        return trim((string) Mage::getStoreConfig(self::XPATH_GOOGLE_CLIENT_ID, $storeId));
    }

    public function isAppleEnabled(?int $storeId = null): bool
    {
        return (bool) Mage::getStoreConfigFlag(self::XPATH_APPLE_ENABLED, $storeId);
    }

    public function getAppleServiceId(?int $storeId = null): string
    {
        return trim((string) Mage::getStoreConfig(self::XPATH_APPLE_SERVICE_ID, $storeId));
    }

    public function isFacebookEnabled(?int $storeId = null): bool
    {
        return (bool) Mage::getStoreConfigFlag(self::XPATH_FACEBOOK_ENABLED, $storeId);
    }

    public function getFacebookAppId(?int $storeId = null): string
    {
        return trim((string) Mage::getStoreConfig(self::XPATH_FACEBOOK_APP_ID, $storeId));
    }

    public function getFacebookAppSecret(?int $storeId = null): string
    {
        return trim((string) Mage::getStoreConfig(self::XPATH_FACEBOOK_APP_SECRET, $storeId));
    }

    public function getEnabledProviders(?int $storeId = null): array
    {
        $providers = [];
        if ($this->isGoogleEnabled($storeId) && $this->getGoogleClientId($storeId)) {
            $providers[] = [
                'code'     => 'google',
                'clientId' => $this->getGoogleClientId($storeId),
            ];
        }
        if ($this->isAppleEnabled($storeId) && $this->getAppleServiceId($storeId)) {
            $providers[] = [
                'code'      => 'apple',
                'serviceId' => $this->getAppleServiceId($storeId),
            ];
        }
        if ($this->isFacebookEnabled($storeId) && $this->getFacebookAppId($storeId)) {
            $providers[] = [
                'code'  => 'facebook',
                'appId' => $this->getFacebookAppId($storeId),
            ];
        }
        return $providers;
    }

    public const SUPPORTED_PROVIDERS = ['google', 'apple', 'facebook'];

    /**
     * Verify a social provider token and resolve it to a Maho customer.
     * Shared by the frontend AuthController (normal Maho session login) and
     * the headless API processor (JWT for the storefront).
     *
     * @return array{customer?: Mage_Customer_Model_Customer, isNew?: bool, linkRequired?: bool, email?: string}
     * @throws Mage_Core_Exception on invalid input / token / link failure
     */
    public function authenticate(string $provider, string $token, ?string $password = null): array
    {
        if (!in_array($provider, self::SUPPORTED_PROVIDERS, true)) {
            throw Mage::exception('Mage_Core', $this->__('Unsupported sign-in provider.'));
        }
        if (trim($token) === '') {
            throw Mage::exception('Mage_Core', $this->__('Token is required.'));
        }

        $providerModel = $this->getProvider($provider);
        try {
            $claims = $providerModel->verifyToken($token);
        } catch (InvalidArgumentException $e) {
            Mage::log("Social auth token rejected ({$provider}): {$e->getMessage()}", null, 'social_login.log');
            throw Mage::exception('Mage_Core', $this->__('Invalid authentication token.'));
        } catch (RuntimeException $e) {
            Mage::log("Social auth provider error ({$provider}): {$e->getMessage()}", null, 'social_login.log');
            throw Mage::exception('Mage_Core', $this->__('Provider verification temporarily unavailable.'));
        }

        $providerId = $claims['sub'];
        $email = $claims['email'] ?? null;

        // 1. Existing social link → that customer
        /** @var MageAustralia_SocialLogin_Model_Social_Identity $identity */
        $identity = Mage::getModel('sociallogin/social_identity')->loadByProviderIdentity($provider, $providerId);
        if ($identity->getId()) {
            $customer = Mage::getModel('customer/customer')->load((int) $identity->getCustomerId());
            if (!$customer->getId()) {
                throw Mage::exception('Mage_Core', $this->__('Linked customer no longer exists.'));
            }
            return ['customer' => $customer, 'isNew' => false];
        }

        if (!$email) {
            throw Mage::exception('Mage_Core', $this->__('Unable to sign in. Please try another method or create an account.'));
        }

        // 2. Existing customer with this email → require password to link
        $customer = Mage::getModel('customer/customer');
        $customer->setWebsiteId(Mage::app()->getStore()->getWebsiteId());
        $customer->loadByEmail($email);
        if ($customer->getId()) {
            if ($password === null || $password === '') {
                return ['linkRequired' => true, 'email' => $this->maskEmail($email)];
            }
            if (!$customer->validatePassword($password)) {
                throw Mage::exception('Mage_Core', $this->__('Incorrect password.'));
            }
            $this->createSocialLink((int) $customer->getId(), $provider, $providerId, $email);
            Mage::log("Social link created (password verified): {$provider} -> customer #{$customer->getId()}", null, 'social_login.log');
            return ['customer' => $customer, 'isNew' => false];
        }

        // 3. New customer
        $customer = $this->createCustomer($claims);
        $this->createSocialLink((int) $customer->getId(), $provider, $providerId, $email);
        Mage::log("Social login new customer: {$provider} -> customer #{$customer->getId()} ({$email})", null, 'social_login.log');
        return ['customer' => $customer, 'isNew' => true];
    }

    public function getProvider(string $code): object
    {
        $className = 'MageAustralia_SocialLogin_Model_Provider_' . ucfirst($code);
        if (!class_exists($className)) {
            throw Mage::exception('Mage_Core', $this->__('Unknown provider: %s', $code));
        }
        return new $className();
    }

    public function createSocialLink(int $customerId, string $provider, string $providerId, ?string $email): void
    {
        Mage::getModel('sociallogin/social_identity')
            ->setCustomerId($customerId)
            ->setProvider($provider)
            ->setProviderId($providerId)
            ->setProviderEmail($email)
            ->save();
    }

    public function createCustomer(array $claims): Mage_Customer_Model_Customer
    {
        /** @var Mage_Customer_Model_Customer $customer */
        $customer = Mage::getModel('customer/customer');
        $store = Mage::app()->getStore();
        $customer->setWebsiteId($store->getWebsiteId());
        $customer->setStore($store);
        $customer->setEmail($claims['email']);
        $customer->setFirstname($claims['given_name'] ?? $claims['name'] ?? 'Customer');
        $customer->setLastname($claims['family_name'] ?? '.');
        $customer->setPassword(Mage::helper('core')->getRandomString(32));
        $customer->setIsActive(1);
        $customer->setConfirmation(null);
        $customer->save();
        return $customer;
    }

    public function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        $masked = substr($local, 0, 1) . str_repeat('*', max(3, strlen($local) - 1));
        return $masked . '@' . $domain;
    }

    public function isOtpEnabled(?int $storeId = null): bool { return (bool) Mage::getStoreConfig('customer/sociallogin/otp_enabled', $storeId); }
    public function isOtpSmsEnabled(?int $storeId = null): bool { return (bool) Mage::getStoreConfig('customer/sociallogin/otp_sms_enabled', $storeId); }
    public function getOtpLength(?int $storeId = null): int { return max(4, min(10, (int) Mage::getStoreConfig('customer/sociallogin/otp_length', $storeId))); }
    public function getOtpExpiryMinutes(?int $storeId = null): int { return max(1, min(10, (int) Mage::getStoreConfig('customer/sociallogin/otp_expiry_minutes', $storeId))); }
    public function getOtpMaxAttempts(?int $storeId = null): int { return max(1, (int) Mage::getStoreConfig('customer/sociallogin/otp_max_attempts', $storeId)); }

    // otp_clickatell_api_key and otp_pepper carry backend_model encrypted on their <default> nodes,
    // so getStoreConfig AUTO-DECRYPTS them. Do NOT decrypt() again (would double-decrypt to empty).
    public function getClickatellApiKey(?int $storeId = null): string { return (string) Mage::getStoreConfig('customer/sociallogin/otp_clickatell_api_key', $storeId); }
    public function getClickatellSender(?int $storeId = null): string { return (string) Mage::getStoreConfig('customer/sociallogin/otp_clickatell_sender', $storeId); }
    public function getOtpPepper(?int $storeId = null): string
    {
        // Encrypted backend_model config auto-decrypts on read (do NOT decrypt() again).
        $pepper = (string) Mage::getStoreConfig('customer/sociallogin/otp_pepper', $storeId);
        if ($pepper === '') {
            // Never hash unsalted: fall back to the install crypt key so a DB-only leak
            // cannot brute-force short codes. A dedicated pepper is still recommended.
            $pepper = (string) Mage::getConfig()->getNode('global/crypt/key');
        }
        return $pepper;
    }

    public function getSmsProvider(?int $storeId = null): string { return (string) Mage::getStoreConfig('customer/sociallogin/otp_sms_provider', $storeId) ?: 'clickatell'; }
    public function getOtpResendCooldown(?int $storeId = null): int { return max(0, (int) Mage::getStoreConfig('customer/sociallogin/otp_resend_cooldown', $storeId)); }

    public function normaliseEmail(string $email): string { return strtolower(trim($email)); }

    /**
     * Country code -> international dialling prefix. Used to expand national-format
     * numbers (those starting with the country's national trunk-0) into E.164.
     */
    private const COUNTRY_DIALING = [
        'AU' => '61',
        'NZ' => '64',
        'GB' => '44',
        'IE' => '353',
        'US' => '1',
        'CA' => '1',
        'SG' => '65',
        'JP' => '81',
        'IN' => '91',
        'DE' => '49',
        'FR' => '33',
    ];

    /**
     * Country code -> mobile-number shape (after normalisation). Used by
     * mobileIsValid() to reject numbers that pass normalisation but aren't real
     * mobile numbers for the selected country (e.g. a typo'd extra digit, or an AU
     * landline 02/03/07/08 misrouted into the SMS path).
     */
    private const MOBILE_PATTERNS = [
        'AU' => '/^\+614\d{8}$/',          // +61 4xx xxx xxx
        'NZ' => '/^\+642\d{7,9}$/',        // +64 2x... (8-10 digits after +64)
        'GB' => '/^\+447\d{9}$/',          // +44 7xxx xxxxxx
        'US' => '/^\+1\d{10}$/',
        'CA' => '/^\+1\d{10}$/',
        'SG' => '/^\+65[89]\d{7}$/',       // +65 8 or 9 + 7
    ];

    public function getDefaultMobileCountry(?int $storeId = null): string
    {
        return strtoupper((string) Mage::getStoreConfig('customer/sociallogin/otp_default_country', $storeId)) ?: 'AU';
    }

    /**
     * Normalise a user-typed mobile number to E.164 (+CCNNN...).
     *
     * Accepted input formats (for the configured default country, e.g. AU):
     *   0400 123 456       -> +61400123456   (national trunk-0)
     *   +61 400 123 456    -> +61400123456   (already E.164)
     *   61400123456        -> +61400123456   (bare country code)
     *   0061 400 123 456   -> +61400123456   (international access via 00)
     *   (0400) 123-456     -> +61400123456   (formatting stripped)
     *
     * Does NOT validate that the result is a real mobile number — that's mobileIsValid().
     */
    public function normaliseMobile(string $mobile, ?string $country = null): string
    {
        $country = strtoupper($country ?: $this->getDefaultMobileCountry());
        $digits = preg_replace('/[^0-9+]/', '', trim($mobile));
        if (!is_string($digits) || $digits === '') {
            return '';
        }

        // "00" international access prefix -> "+" (some keypads emit "0061..." instead of "+61...")
        if (str_starts_with($digits, '00')) {
            $digits = '+' . substr($digits, 2);
        }

        // Already E.164 — accept as-is.
        if (str_starts_with($digits, '+')) {
            return $digits;
        }

        $cc = self::COUNTRY_DIALING[$country] ?? null;

        // National format with leading 0 (AU "0400...", UK "07..."): swap trunk-0 for country code.
        if (str_starts_with($digits, '0')) {
            return $cc !== null
                ? '+' . $cc . substr($digits, 1)
                : '+' . ltrim($digits, '0');
        }

        // Bare digits, no prefix — assume they already include the country code (e.g. "61400...").
        return '+' . $digits;
    }

    /**
     * True if $mobile is a valid mobile number in E.164 form for the given country.
     * Falls back to a generic E.164 length check for countries without a specific pattern.
     */
    public function mobileIsValid(string $mobile, ?string $country = null): bool
    {
        if ($mobile === '' || !str_starts_with($mobile, '+')) {
            return false;
        }
        $country = strtoupper($country ?: $this->getDefaultMobileCountry());
        if (isset(self::MOBILE_PATTERNS[$country])) {
            return (bool) preg_match(self::MOBILE_PATTERNS[$country], $mobile);
        }
        // Generic E.164: + followed by 8-15 digits, first digit not 0.
        return (bool) preg_match('/^\+[1-9]\d{7,14}$/', $mobile);
    }

    /**
     * Find the best candidate mobile number from a customer's address book.
     * Walks all addresses, normalises each telephone, returns the first one that
     * passes mobileIsValid() for the default country. Prefers the default billing
     * address; ties broken by highest address entity_id (most recent).
     *
     * Used by the OTP delivery fallback and the address-mobile promotion paths
     * (CLI + admin button) when a customer has no mobile/mobile_verified on file
     * but a usable mobile is sitting on their address.
     */
    public function findValidMobileFromAddresses(Mage_Customer_Model_Customer $customer): ?string
    {
        $country = $this->getDefaultMobileCountry();
        $candidates = [];
        /** @var Mage_Customer_Model_Address $address */
        foreach ($customer->getAddresses() as $address) {
            $phone = (string) $address->getTelephone();
            if ($phone === '') {
                continue;
            }
            $normalised = $this->normaliseMobile($phone, $country);
            if (!$this->mobileIsValid($normalised, $country)) {
                continue;
            }
            $candidates[] = [
                'addr_id'            => (int) $address->getId(),
                'mobile'             => $normalised,
                'is_default_billing' => (int) (bool) $address->getIsDefaultBilling(),
            ];
        }
        if ($candidates === []) {
            return null;
        }
        usort($candidates, function (array $a, array $b): int {
            return ($b['is_default_billing'] <=> $a['is_default_billing'])
                ?: ($b['addr_id'] <=> $a['addr_id']);
        });
        return $candidates[0]['mobile'];
    }

    /**
     * Pre-approve a mobile by copying the best valid candidate from the customer's
     * address book onto their customer record (mobile + mobile_verified=NOW()).
     *
     * Idempotent: no-op when the customer already has mobile_verified set, so it's
     * safe to run repeatedly from the CLI sweep, the admin button, or future jobs.
     *
     * Returns the mobile that was set (or already present), or null if nothing to do.
     */
    public function promoteAddressMobileToCustomer(int $customerId): ?string
    {
        /** @var Mage_Customer_Model_Customer $customer */
        $customer = Mage::getModel('customer/customer')->load($customerId);
        if (!$customer->getId()) {
            return null;
        }
        if ($customer->getMobileVerified()) {
            $existing = (string) $customer->getMobile();
            return $existing !== '' ? $existing : null;
        }
        $mobile = $this->findValidMobileFromAddresses($customer);
        if ($mobile === null) {
            return null;
        }
        $customer->setMobile($mobile)
            ->setMobileVerified(Mage_Core_Model_Locale::nowUtc())
            ->save();
        return $mobile;
    }
}
