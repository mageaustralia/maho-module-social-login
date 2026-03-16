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
}
