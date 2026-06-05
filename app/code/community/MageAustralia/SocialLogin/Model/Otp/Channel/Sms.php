<?php

declare(strict_types=1);

class MageAustralia_SocialLogin_Model_Otp_Channel_Sms implements MageAustralia_SocialLogin_Model_Otp_ChannelInterface
{
    #[\Override]
    public function send(string $to, string $code, string $purpose, ?int $storeId = null): bool
    {
        if (!Mage::helper('sociallogin')->isOtpSmsEnabled($storeId)) {
            return false;
        }
        $message = 'Your verification code is: ' . $code;
        return Mage::helper('sociallogin/sms')->send($to, $message, $storeId);
    }
}
