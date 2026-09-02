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
        // facebook_app_secret carries backend_model="adminhtml/system_config_backend_encrypted"
        // on its <default> node in config.xml, and Mage_Core_Model_Store::_processConfigValue()
        // runs that backend model's afterLoad() — so getStoreConfig() has ALREADY decrypted it.
        // Decrypting a second time returned an empty string, and Facebook login therefore
        // always threw "not configured". Same trap the OTP keys carry a comment about in
        // Helper/Data.php.
        $appSecret = $helper->getFacebookAppSecret();

        if (empty($appId) || empty($appSecret)) {
            throw new \InvalidArgumentException('Facebook Login is not configured');
        }

        // 1. Verify the token is valid and belongs to our app
        $debugUrl = self::GRAPH_API_URL . '/debug_token?'
            . http_build_query(['input_token' => $accessToken, 'access_token' => $appId . '|' . $appSecret]);

        $debug = Mage::helper('sociallogin')->fetchJson($debugUrl);
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

        $profile = Mage::helper('sociallogin')->fetchJson($profileUrl);
        if (!empty($profile['error'])) {
            throw new \RuntimeException('Invalid Facebook profile response');
        }

        if (empty($profile['email'])) {
            throw new \InvalidArgumentException('Email not available from Facebook. Please grant email permission.');
        }

        return [
            'sub'   => (string) $profile['id'],
            'email' => strtolower((string) $profile['email']),
            // Facebook's Graph API exposes no email-verification claim, so this
            // cannot be asserted. It used to say true, which is a claim the
            // provider never made -- and auto-link trusts this flag to sign a
            // customer into an existing account with no password. Report false
            // and let the caller decide.
            'email_verified' => false,
            'name'           => $profile['name'] ?? null,
            'given_name'     => $profile['first_name'] ?? null,
            'family_name'    => $profile['last_name'] ?? null,
        ];
    }
}
