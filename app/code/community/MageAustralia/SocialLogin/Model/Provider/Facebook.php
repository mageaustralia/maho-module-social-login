<?php

declare(strict_types=1);

class MageAustralia_SocialLogin_Model_Provider_Facebook implements MageAustralia_SocialLogin_Model_Provider_ProviderInterface
{
    private const GRAPH_API_URL = 'https://graph.facebook.com/v19.0';

    public function getCode(): string
    {
        return 'facebook';
    }

    public function verifyToken(string $accessToken): array
    {
        $helper = Mage::helper('sociallogin');
        $appId = $helper->getFacebookAppId();
        $appSecret = Mage::helper('core')->decrypt($helper->getFacebookAppSecret());

        if (empty($appId) || empty($appSecret)) {
            throw new \InvalidArgumentException('Facebook Login is not configured');
        }

        // 1. Verify the token is valid and belongs to our app
        $debugUrl = self::GRAPH_API_URL . '/debug_token?'
            . http_build_query(['input_token' => $accessToken, 'access_token' => $appId . '|' . $appSecret]);

        $debugJson = file_get_contents($debugUrl);
        if ($debugJson === false) {
            throw new \RuntimeException('Failed to verify Facebook token');
        }

        $debug = json_decode($debugJson, true);
        $debugData = $debug['data'] ?? [];

        if (empty($debugData['is_valid'])) {
            throw new \InvalidArgumentException('Invalid authentication token');
        }

        if (($debugData['app_id'] ?? '') !== $appId) {
            throw new \InvalidArgumentException('Invalid authentication token');
        }

        if (($debugData['expires_at'] ?? 0) > 0 && $debugData['expires_at'] < time()) {
            throw new \InvalidArgumentException('Authentication token has expired');
        }

        $userId = (string) ($debugData['user_id'] ?? '');
        if (empty($userId)) {
            throw new \InvalidArgumentException('Invalid authentication token');
        }

        // 2. Fetch user profile
        $profileUrl = self::GRAPH_API_URL . '/me?'
            . http_build_query([
                'fields' => 'id,email,first_name,last_name,name',
                'access_token' => $accessToken,
            ]);

        $profileJson = file_get_contents($profileUrl);
        if ($profileJson === false) {
            throw new \RuntimeException('Failed to fetch user profile from Facebook');
        }

        $profile = json_decode($profileJson, true);
        if (!is_array($profile) || !empty($profile['error'])) {
            throw new \RuntimeException('Invalid Facebook profile response');
        }

        if (empty($profile['email'])) {
            throw new \InvalidArgumentException('Email not available from Facebook. Please grant email permission.');
        }

        return [
            'sub'            => (string) $profile['id'],
            'email'          => strtolower((string) $profile['email']),
            'email_verified' => true,
            'name'           => $profile['name'] ?? null,
            'given_name'     => $profile['first_name'] ?? null,
            'family_name'    => $profile['last_name'] ?? null,
        ];
    }
}
