<?php

declare(strict_types=1);

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

class MageAustralia_SocialLogin_Model_Provider_Google implements MageAustralia_SocialLogin_Model_Provider_ProviderInterface
{
    private const JWKS_URL  = 'https://www.googleapis.com/oauth2/v3/certs';
    private const ISSUER    = ['https://accounts.google.com', 'accounts.google.com'];
    private const CACHE_TTL = 21600; // 6 hours

    private static ?array $cachedKeys = null;
    private static int $cacheExpires = 0;

    public function getCode(): string
    {
        return 'google';
    }

    public function verifyToken(string $idToken): array
    {
        $helper = Mage::helper('sociallogin');
        $clientId = $helper->getGoogleClientId();
        if (empty($clientId)) {
            throw new \InvalidArgumentException('Google Sign-In is not configured');
        }

        $keys = $this->getJwks();

        try {
            $payload = JWT::decode($idToken, $keys);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Invalid authentication token');
        }

        $payload = (array) $payload;

        if (($payload['aud'] ?? '') !== $clientId) {
            throw new \InvalidArgumentException('Invalid authentication token');
        }

        if (!in_array($payload['iss'] ?? '', self::ISSUER, true)) {
            throw new \InvalidArgumentException('Invalid authentication token');
        }

        if (empty($payload['exp']) || (int) $payload['exp'] < time()) {
            throw new \InvalidArgumentException('Authentication token has expired');
        }

        if (empty($payload['email']) || empty($payload['email_verified'])) {
            throw new \InvalidArgumentException('Email not verified by provider');
        }

        return [
            'sub'            => (string) $payload['sub'],
            'email'          => strtolower((string) $payload['email']),
            'email_verified' => (bool) $payload['email_verified'],
            'name'           => $payload['name'] ?? null,
            'given_name'     => $payload['given_name'] ?? null,
            'family_name'    => $payload['family_name'] ?? null,
        ];
    }

    private function getJwks(): array
    {
        if (self::$cachedKeys !== null && time() < self::$cacheExpires) {
            return self::$cachedKeys;
        }

        $json = file_get_contents(self::JWKS_URL);
        if ($json === false) {
            throw new \RuntimeException('Failed to fetch provider keys');
        }

        $jwks = json_decode($json, true);
        if (!is_array($jwks)) {
            throw new \RuntimeException('Invalid provider key response');
        }

        self::$cachedKeys = JWK::parseKeySet($jwks);
        self::$cacheExpires = time() + self::CACHE_TTL;
        return self::$cachedKeys;
    }
}
