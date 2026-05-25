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
        // @phpstan-ignore-next-line method.notFound (getStore exists at runtime; absent in PHPStan stub)
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
        // @phpstan-ignore-next-line method.notFound (getStore exists at runtime; absent in PHPStan stub)
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
}
