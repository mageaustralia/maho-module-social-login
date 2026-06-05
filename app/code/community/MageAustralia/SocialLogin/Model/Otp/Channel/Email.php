<?php

declare(strict_types=1);

class MageAustralia_SocialLogin_Model_Otp_Channel_Email implements MageAustralia_SocialLogin_Model_Otp_ChannelInterface
{
    #[\Override]
    public function send(string $to, string $code, string $purpose, ?int $storeId = null): bool
    {
        try {
            // Maho has no legacy core/email model; send an ad-hoc plain-text message via
            // core/email_template configured as a TYPE_TEXT template (no DB template needed).
            /** @var Mage_Core_Model_Email_Template $mail */
            $mail = Mage::getModel('core/email_template');
            $mail->setSenderEmail((string) Mage::getStoreConfig('trans_email/ident_general/email', $storeId))
                ->setSenderName((string) Mage::getStoreConfig('trans_email/ident_general/name', $storeId))
                ->setTemplateType(Mage_Core_Model_Template::TYPE_TEXT)
                ->setTemplateSubject('Your verification code')
                ->setTemplateText('Your verification code is: ' . $code . "\nIt expires shortly. If you did not request it, ignore this email.");
            return (bool) $mail->send($to);
        } catch (\Throwable $e) {
            Mage::log('otp email send failed for ' . $to . ': ' . $e->getMessage(), Mage::LOG_ERR, 'sociallogin_otp.log');
            return false;
        }
    }
}
