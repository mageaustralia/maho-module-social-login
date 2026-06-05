<?php

declare(strict_types=1);

interface MageAustralia_SocialLogin_Model_Sms_ProviderInterface
{
    /** Send an arbitrary SMS. Returns true on success; must never throw out. */
    public function send(string $to, string $message, ?int $storeId = null): bool;
}
