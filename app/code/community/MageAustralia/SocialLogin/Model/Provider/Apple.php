<?php

declare(strict_types=1);

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

class MageAustralia_SocialLogin_Model_Provider_Apple implements MageAustralia_SocialLogin_Model_Provider_ProviderInterface
{
    private const JWKS_URL  = 'https://appleid.apple.com/auth/keys';
    private const ISSUER    = 'https://appleid.apple.com';
    private const CACHE_TTL = 21600; // 6 hours

    private static ?array $cachedKeys = null;
    private static int $cacheExpires = 0;

    public function getCode(): string
    {
        return 'apple';
    }

    public function verifyToken(string $idToken): array
    {
        $helper = Mage::helper('sociallogin');
        $serviceId = $helper->getAppleServiceId();
        if (empty($serviceId)) {
            throw new \InvalidArgumentException('Apple Sign-In is not configured');
        }

        $keys = $this->getJwks();

        try {
            $payload = JWT::decode($idToken, $keys);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Invalid authentication token');
        }

        $payload = (array) $payload;

        if (($payload['aud'] ?? '') !== $serviceId) {
            throw new \InvalidArgumentException('Invalid authentication token');
        }

        if (($payload['iss'] ?? '') !== self::ISSUER) {
            throw new \InvalidArgumentException('Invalid authentication token');
        }

        if (empty($payload['exp']) || (int) $payload['exp'] < time()) {
            throw new \InvalidArgumentException('Authentication token has expired');
        }

        $email = $payload['email'] ?? null;
        $emailVerified = $payload['email_verified'] ?? false;
        if (is_string($emailVerified)) {
            // Apple sends this as the STRING "true"/"false", not a boolean.
            $emailVerified = $emailVerified === 'true';
        }

        // Apple always sends the claim, so a false here is the provider stating
        // the address is NOT verified -- not merely declining to say. Refuse it,
        // as the Google provider already does. (Facebook exposes no equivalent
        // claim at all; absence is handled by never auto-linking, not by
        // rejecting the sign-in.)
        if ($email && !$emailVerified) {
            throw new \InvalidArgumentException('Apple reports this email address as unverified');
        }

        return [
            'sub'            => (string) $payload['sub'],
            'email'          => $email ? strtolower((string) $email) : null,
            'email_verified' => (bool) $emailVerified,
            'name'           => null,
            'given_name'     => null,
            'family_name'    => null,
        ];
    }

    private function getJwks(): array
    {
        if (self::$cachedKeys !== null && time() < self::$cacheExpires) {
            return self::$cachedKeys;
        }

        $jwks = \Mage::helper('sociallogin')->fetchJson(self::JWKS_URL);

        self::$cachedKeys = JWK::parseKeySet($jwks);
        self::$cacheExpires = time() + self::CACHE_TTL;
        return self::$cachedKeys;
    }
}
