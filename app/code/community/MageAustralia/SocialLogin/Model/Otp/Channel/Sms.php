<?php

declare(strict_types=1);

class MageAustralia_SocialLogin_Model_Otp_Channel_Sms implements MageAustralia_SocialLogin_Model_Otp_ChannelInterface
{
    #[\Override]
    public function send(string $to, string $code, string $purpose, ?int $storeId = null): bool
    {
        $helper = Mage::helper('sociallogin');
        if (!$helper->isOtpSmsEnabled($storeId)) {
            return false;
        }
        $apiKey = $helper->getClickatellApiKey($storeId);
        $sender = $helper->getClickatellSender($storeId);
        if ($apiKey === '') {
            return false;
        }
        try {
            $client = \Symfony\Component\HttpClient\HttpClient::create(['timeout' => 10]);
            $response = $client->request('POST', 'https://platform.clickatell.com/v1/message', [
                'headers' => ['Authorization' => $apiKey, 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
                'json' => ['messages' => [array_filter([
                    'channel' => 'sms',
                    'to'      => $to,
                    'from'    => $sender !== '' ? $sender : null,
                    'content' => 'Your verification code is: ' . $code,
                ])]],
            ]);
            $status = $response->getStatusCode();
            $ok = $status >= 200 && $status < 300;
            if (!$ok) {
                Mage::log('otp sms non-2xx (' . $status . ') for ' . $to, Mage::LOG_ERR, 'sociallogin_otp.log');
            }
            return $ok;
        } catch (\Throwable $e) {
            Mage::log('otp sms send failed for ' . $to . ': ' . $e->getMessage(), Mage::LOG_ERR, 'sociallogin_otp.log');
            return false;
        }
    }
}
