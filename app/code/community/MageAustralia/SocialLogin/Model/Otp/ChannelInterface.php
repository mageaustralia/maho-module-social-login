<?php

declare(strict_types=1);

interface MageAustralia_SocialLogin_Model_Otp_ChannelInterface
{
    /** Send the code to the recipient. Returns true on success; must never throw out. */
    public function send(string $to, string $code, string $purpose, ?int $storeId = null): bool;
}
