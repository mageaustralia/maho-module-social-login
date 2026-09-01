<?php

declare(strict_types=1);

class MageAustralia_SocialLogin_Model_Sms_Provider_Clickatell implements MageAustralia_SocialLogin_Model_Sms_ProviderInterface
{
    #[\Override]
    public function send(string $to, string $message, ?int $storeId = null): bool
    {
        $helper = Mage::helper('sociallogin');
        $apiKey = $helper->getClickatellApiKey($storeId);
        $sender = $helper->getClickatellSender($storeId);
        if ($apiKey === '') {
            return false;
        }
        try {
            $client = \Symfony\Component\HttpClient\HttpClient::create(['timeout' => 10]);
            // Clickatell One API current endpoint is /messages (Bearer auth).
            // The older /v1/message still responds but is deprecated.
            $response = $client->request('POST', 'https://platform.clickatell.com/messages', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'json' => array_filter([
                    'content' => $message,
                    'to'      => [$to],
                    'from'    => $sender !== '' ? $sender : null,
                    'charset' => 'UTF-8',
                ], static fn($v) => $v !== null),
            ]);
            $status = $response->getStatusCode();
            $ok = $status >= 200 && $status < 300;
            if (!$ok) {
                // Capture the response body so the operator can see Clickatell's verbose
                // reason (e.g. "Invalid or missing integration API Key", "Sender ID not
                // approved") instead of just the bare HTTP status.
                $body = '';
                try {
                    $body = (string) $response->getContent(false);
                } catch (\Throwable) {
                    // ignore
                }
                Mage::log(
                    sprintf('otp sms clickatell non-2xx (%d) for %s :: %s', $status, $to, $body),
                    // @phpstan-ignore-next-line classConstant.notFound (Mage::LOG_ERROR exists at runtime; bundled PHPStan stub is stale)
                    Mage::LOG_ERROR,
                    'sociallogin_otp.log',
                );
            }
            return $ok;
        } catch (\Throwable $e) {
            /** @phpstan-ignore classConstant.notFound */
            Mage::log('otp sms clickatell send failed for ' . $to . ': ' . $e->getMessage(), Mage::LOG_ERROR, 'sociallogin_otp.log');
            return false;
        }
    }
}
