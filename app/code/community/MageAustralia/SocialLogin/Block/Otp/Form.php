<?php

declare(strict_types=1);

class MageAustralia_SocialLogin_Block_Otp_Form extends Mage_Core_Block_Template
{
    public function isEnabled(): bool
    {
        return Mage::helper('sociallogin')->isOtpEnabled();
    }

    public function getRequestUrl(): string
    {
        return $this->getUrl('sociallogin/otp/request');
    }

    public function getVerifyUrl(): string
    {
        return $this->getUrl('sociallogin/otp/verify');
    }

    public function getFormKey(): string
    {
        return Mage::getSingleton('core/session')->getFormKey();
    }

    public function getResendCooldown(): int
    {
        return Mage::helper('sociallogin')->getOtpResendCooldown();
    }
}
