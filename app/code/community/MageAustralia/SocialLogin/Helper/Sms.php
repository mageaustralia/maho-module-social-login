<?php

declare(strict_types=1);

class MageAustralia_SocialLogin_Helper_Sms extends Mage_Core_Helper_Abstract
{
    /** Send an SMS via the admin-selected provider. Returns false (never throws) if disabled/unconfigured. */
    public function send(string $to, string $message, ?int $storeId = null): bool
    {
        $provider = $this->getProvider($storeId);
        if (!$provider) {
            return false;
        }
        return $provider->send($to, $message, $storeId);
    }

    public function getProvider(?int $storeId = null): ?MageAustralia_SocialLogin_Model_Sms_ProviderInterface
    {
        $code = Mage::helper('sociallogin')->getSmsProvider($storeId);
        if ($code === '') {
            return null;
        }
        $model = Mage::getModel('sociallogin/sms_provider_' . $code);
        return $model instanceof MageAustralia_SocialLogin_Model_Sms_ProviderInterface ? $model : null;
    }
}
