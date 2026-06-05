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

    public function normaliseMobile(string $mobile): string
    {
        $digits = preg_replace('/[^0-9+]/', '', trim($mobile));
        if ($digits === null) { return ''; }
        if (strpos($digits, '0') === 0) { $digits = '+61' . substr($digits, 1); } // AU default; adjust per locale
        if (strpos($digits, '+') !== 0 && $digits !== '') { $digits = '+' . $digits; }
        return $digits;
    }

    /**
     * Request an OTP to link a social account to the existing customer that owns the
     * provider email. The email is derived from the VERIFIED social token, never from
     * the client, so a caller cannot trigger a link code for an arbitrary address.
     * Enumeration-safe: returns the same shape regardless of account existence.
     *
     * @return array{ok: bool}
     */
    public function requestOtpLink(string $provider, string $token, ?int $storeId = null, ?string $ip = null): array
    {
        $claims = $this->getProvider($provider)->verifyToken($token); // throws on invalid token
        $email = $claims['email'] ?? null;
        if (!$email) {
            throw Mage::exception('Mage_Core', $this->__('Unable to link. No email on the social account.'));
        }
        Mage::helper('sociallogin/otp')->requestCode($this->normaliseEmail($email), 'link', 'email', $storeId, $ip);
        return ['ok' => true];
    }

    /**
     * Complete an OTP-based social link: re-verify the token (to re-derive email +
     * providerId server-side), verify the OTP, then attach the identity to the customer
     * that owns the email. Returns the customer on success for the caller to log in.
     *
     * @return array{ok: bool, customer?: Mage_Customer_Model_Customer}
     */
    public function completeOtpLink(string $provider, string $token, string $code, ?int $storeId = null): array
    {
        $claims = $this->getProvider($provider)->verifyToken($token);
        $providerId = (string) $claims['sub'];
        $email = $claims['email'] ?? null;
        if (!$email) {
            return ['ok' => false];
        }
        $normEmail = $this->normaliseEmail($email);
        $res = Mage::helper('sociallogin/otp')->verifyCode($normEmail, 'link', $code, $storeId);
        if (empty($res['ok'])) {
            return ['ok' => false];
        }
        /** @var Mage_Customer_Model_Customer $customer */
        $customer = Mage::getModel('customer/customer')
            ->setWebsiteId((int) Mage::app()->getStore()->getWebsiteId())
            ->loadByEmail($normEmail);
        if (!$customer->getId()) {
            return ['ok' => false];
        }
        // Idempotent: only create the link if it does not already exist.
        $existing = Mage::getModel('sociallogin/social_identity')->loadByProviderIdentity($provider, $providerId);
        if (!$existing->getId()) {
            $this->createSocialLink((int) $customer->getId(), $provider, $providerId, $email);
        }
        return ['ok' => true, 'customer' => $customer];
    }
}
