<?php

declare(strict_types=1);

interface MageAustralia_SocialLogin_Model_Provider_ProviderInterface
{
    /**
     * Verify an ID token from the provider.
     *
     * @return array{sub: string, email: string, email_verified: bool, name?: string, given_name?: string, family_name?: string}
     * @throws \InvalidArgumentException If the token is invalid
     */
    public function verifyToken(string $idToken): array;

    /**
     * Get the provider code (e.g. 'google', 'apple').
     */
    public function getCode(): string;
}
