<?php

declare(strict_types=1);

namespace MageAustralia\SocialLogin\Api\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use MageAustralia\SocialLogin\Api\Resource\SocialAuth;
use Maho\ApiPlatform\Service\JwtService;
use Maho\ApiPlatform\Service\RateLimiter;

class SocialAuthProcessor implements ProcessorInterface
{
    private const SUPPORTED_PROVIDERS = ['google', 'apple', 'facebook'];

    /**
     * @param SocialAuth $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SocialAuth
    {
        // Rate limiting — 10 attempts per IP per minute
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $rateLimiter = new RateLimiter();
        $rateLimiter->check("social_auth:ip:{$ip}", 10, 60);

        if (empty($data->provider) || !in_array($data->provider, self::SUPPORTED_PROVIDERS, true)) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException(
                'Invalid provider. Supported: ' . implode(', ', self::SUPPORTED_PROVIDERS)
            );
        }

        if (empty($data->token)) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Token is required');
        }

        // 1. Verify the token with the provider
        $provider = $this->getProvider($data->provider);
        try {
            $claims = $provider->verifyToken($data->token);
        } catch (\InvalidArgumentException $e) {
            \Mage::log("Social auth token rejected ({$data->provider}): {$e->getMessage()}", null, 'social_login.log');
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid authentication token');
        } catch (\RuntimeException $e) {
            \Mage::log("Social auth provider error ({$data->provider}): {$e->getMessage()}", null, 'social_login.log');
            throw new \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException(null, 'Provider verification temporarily unavailable');
        }

        $providerId = $claims['sub'];
        $email = $claims['email'] ?? null;
        $isNewCustomer = false;

        // 2. Look up by provider + provider_id
        $resource = \Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');
        $socialTable = $resource->getTableName('mageaustralia_social_login');

        $existingLink = $read->fetchRow(
            $read->select()
                ->from($socialTable)
                ->where('provider = ?', $data->provider)
                ->where('provider_id = ?', $providerId)
                ->limit(1),
        );

        if ($existingLink) {
            // Existing social link — load customer
            $customer = \Mage::getModel('customer/customer')->load((int) $existingLink['customer_id']);
            if (!$customer->getId()) {
                throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Linked customer no longer exists');
            }
            \Mage::log("Social login: {$data->provider} user {$providerId} → customer #{$customer->getId()}", null, 'social_login.log');
        } elseif ($email) {
            // 3. Look up customer by verified email
            $customer = \Mage::getModel('customer/customer');
            $customer->setWebsiteId(\Mage::app()->getStore()->getWebsiteId());
            $customer->loadByEmail($email);

            if ($customer->getId()) {
                // Existing customer with same email — require verification before linking.
                // Two options: password verification (instant) or email confirmation link.
                if (!empty($data->password)) {
                    // Option A: Verify password inline
                    if (!$customer->validatePassword($data->password)) {
                        // Rate limit password attempts per email
                        $rateLimiter->check('social_link:email:' . strtolower($email), 5, 300);
                        throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Incorrect password');
                    }
                    $this->createSocialLink((int) $customer->getId(), $data->provider, $providerId, $email);
                    \Mage::log("Social link created (password verified): {$data->provider} → customer #{$customer->getId()} ({$email})", null, 'social_login.log');
                } else {
                    // No password provided — tell the client to prompt for one
                    $data->id = 'link-required';
                    $data->linkRequired = 'account_exists';
                    $data->customer = [
                        'email' => $this->maskEmail($email),
                    ];
                    $data->token = null;
                    return $data;
                }
            } else {
                // 4. Create new customer
                $customer = $this->createCustomer($claims);
                $this->createSocialLink((int) $customer->getId(), $data->provider, $providerId, $email);
                $isNewCustomer = true;
                \Mage::log("Social login new customer: {$data->provider} → customer #{$customer->getId()} ({$email})", null, 'social_login.log');
            }
        } else {
            // No email from provider and no existing link
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException(
                'Unable to sign in. Please try another method or create an account.'
            );
        }

        // Generate Maho JWT
        $jwtService = new JwtService();
        $token = $jwtService->generateCustomerToken($customer);

        // Cart merge logic (mirrors AuthController)
        $cartId = null;
        $cartQty = 0;
        if (!empty($data->maskedId) && preg_match('/^[a-f0-9]{32}$/i', $data->maskedId)) {
            $merged = $this->mergeGuestCart($data->maskedId, $customer);
            $cartId = $merged['maskedId'];
            $cartQty = $merged['qty'];
        }

        if (!$cartId) {
            $existingCart = $this->getCustomerCart($customer);
            if ($existingCart) {
                $cartId = $existingCart->getMaskedQuoteId();
                $cartQty = (int) $existingCart->getItemsQty();
            }
        }

        // Build response
        $data->id = 'social-auth-' . $customer->getId();
        $data->authToken = $token;
        $data->customer = [
            'id'        => (int) $customer->getId(),
            'email'     => $customer->getEmail(),
            'firstName' => $customer->getFirstname(),
            'lastName'  => $customer->getLastname(),
        ];
        $data->cartMaskedId = $cartId;
        $data->cartItemsQty = $cartQty;
        $data->isNewCustomer = $isNewCustomer;

        // Clear sensitive input fields from response
        $data->token = null;
        $data->maskedId = null;
        $data->password = null;

        return $data;
    }

    private function getProvider(string $code): object
    {
        $className = 'MageAustralia_SocialLogin_Model_Provider_' . ucfirst($code);
        if (!class_exists($className)) {
            \Mage::getConfig()->getModelClassName("sociallogin/provider_{$code}");
            if (!class_exists($className)) {
                throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException("Unknown provider: {$code}");
            }
        }
        return new $className();
    }

    private function createSocialLink(int $customerId, string $provider, string $providerId, ?string $email): void
    {
        $resource = \Mage::getSingleton('core/resource');
        $write = $resource->getConnection('core_write');
        $write->insert(
            $resource->getTableName('mageaustralia_social_login'),
            [
                'customer_id'    => $customerId,
                'provider'       => $provider,
                'provider_id'    => $providerId,
                'provider_email' => $email,
            ],
        );
    }

    private function createCustomer(array $claims): \Mage_Customer_Model_Customer
    {
        /** @var \Mage_Customer_Model_Customer $customer */
        $customer = \Mage::getModel('customer/customer');
        $customer->setWebsiteId(\Mage::app()->getStore()->getWebsiteId());
        $customer->setStore(\Mage::app()->getStore());

        $customer->setEmail($claims['email']);
        $customer->setFirstname($claims['given_name'] ?? $claims['name'] ?? 'Customer');
        $customer->setLastname($claims['family_name'] ?? '.');
        $customer->setPassword(\Mage::helper('core')->getRandomString(32));
        $customer->setIsActive(1);
        $customer->setConfirmation(null);

        $customer->save();
        return $customer;
    }

    /**
     * Mask email for display: m****@example.com
     */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        $masked = substr($local, 0, 1) . str_repeat('*', max(3, strlen($local) - 1));
        return $masked . '@' . $domain;
    }

    private function mergeGuestCart(string $maskedId, \Mage_Customer_Model_Customer $customer): array
    {
        $resource = \Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');
        $quoteTable = $resource->getTableName('sales/quote');

        $guestQuoteId = $read->fetchOne(
            $read->select()
                ->from($quoteTable, ['entity_id'])
                ->where('masked_quote_id = ?', $maskedId)
                ->where('is_active = ?', 1),
        );

        if (!$guestQuoteId) {
            return ['maskedId' => null, 'qty' => 0];
        }

        /** @var \Mage_Sales_Model_Quote $guestCart */
        $guestCart = \Mage::getModel('sales/quote')->loadByIdWithoutStore((int) $guestQuoteId);
        if (!$guestCart->getId() || !$guestCart->getItemsCount()) {
            return ['maskedId' => null, 'qty' => 0];
        }

        /** @var \Mage_Sales_Model_Quote $customerCart */
        $customerCart = \Mage::getModel('sales/quote');
        $customerCart->setStore(\Mage::app()->getStore());
        $customerCart->loadByCustomer($customer);

        if (!$customerCart->getId()) {
            $customerCart->setCustomer($customer);
            $customerCart->setStore(\Mage::app()->getStore());
            $customerCart->save();
        }

        $customerCart->merge($guestCart);
        $customerCart->collectTotals()->save();

        $guestCart->setIsActive(false)->save();

        return [
            'maskedId' => $customerCart->getMaskedQuoteId(),
            'qty'      => (int) $customerCart->getItemsQty(),
        ];
    }

    private function getCustomerCart(\Mage_Customer_Model_Customer $customer): ?\Mage_Sales_Model_Quote
    {
        /** @var \Mage_Sales_Model_Quote $quote */
        $quote = \Mage::getModel('sales/quote');
        $quote->setStore(\Mage::app()->getStore());
        $quote->loadByCustomer($customer);

        if ($quote->getId() && $quote->getIsActive()) {
            return $quote;
        }

        return null;
    }
}
