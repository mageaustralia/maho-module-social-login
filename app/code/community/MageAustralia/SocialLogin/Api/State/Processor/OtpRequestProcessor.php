<?php

declare(strict_types=1);

namespace MageAustralia\SocialLogin\Api\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use MageAustralia\SocialLogin\Api\Resource\OtpRequest;

/**
 * Headless (storefront) OTP request: dispatches a one-time code for login,
 * register, or link. Always enumeration-safe - swallows every error and
 * returns the same uniform body so account existence is never revealed.
 */
class OtpRequestProcessor implements ProcessorInterface
{
    /**
     * @param OtpRequest $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): OtpRequest
    {
        $purpose = $data->purpose ?: 'login';
        $storeId = (int) \Mage::app()->getStore()->getId();
        $ip = (string) \Mage::helper('core/http')->getRemoteAddr();

        try {
            if ($purpose === 'link') {
                \Mage::helper('sociallogin')->requestOtpLink(
                    (string) ($data->provider ?? ''),
                    (string) ($data->token ?? ''),
                    $storeId,
                    $ip,
                );
            } else {
                $channel = $data->channel ?: 'email';
                $identifier = $channel === 'sms'
                    ? \Mage::helper('sociallogin')->normaliseMobile((string) ($data->identifier ?? ''))
                    : \Mage::helper('sociallogin')->normaliseEmail((string) ($data->identifier ?? ''));
                \Mage::helper('sociallogin/otp')->requestCode($identifier, $purpose, $channel, $storeId, $ip);
            }
        } catch (\Throwable $e) {
            \Mage::log('otp api request error: ' . $e->getMessage(), \Mage::LOG_ERR, 'sociallogin_otp.log');
        }

        $data->id = 'otp-request';
        $data->requested = true;
        $data->message = 'If your details match an account, a verification code has been sent.';
        $data->token = null; // clear sensitive input

        return $data;
    }
}
