<?php

declare(strict_types=1);

namespace MageAustralia\SocialLogin\Api\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use MageAustralia\SocialLogin\Api\Resource\SocialAuth;
use Maho\ApiPlatform\Service\JwtService;

/**
 * Headless (storefront) social-auth: verifies the provider credential via the
 * shared helper, then mints a Maho JWT and merges the guest cart. Normal Maho
 * frontend logins go through MageAustralia_SocialLogin_AuthController instead.
 */
class SocialAuthProcessor implements ProcessorInterface
{
    /**
     * @param SocialAuth $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SocialAuth
    {
        try {
            $result = \Mage::helper('sociallogin')->authenticate(
                (string) ($data->provider ?? ''),
                (string) ($data->token ?? ''),
                (isset($data->password) && $data->password !== '') ? (string) $data->password : null,
            );
        } catch (\Mage_Core_Exception $e) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException($e->getMessage());
        }

        // Existing account with this email — client must supply a password to link.
        if (!empty($result['linkRequired'])) {
            $data->id = 'link-required';
            $data->linkRequired = 'account_exists';
            $data->customer = ['email' => $result['email']];
            $data->token = null;
            $data->password = null;
            return $data;
        }

        /** @var \Mage_Customer_Model_Customer $customer */
        $customer = $result['customer'];
        $isNewCustomer = !empty($result['isNew']);

        // Maho JWT for the storefront session
        $jwtService = new JwtService();
        $jwt = $jwtService->generateCustomerToken($customer);

        // Merge a storefront guest cart into the customer's cart if supplied
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

        $data->id = 'social-auth-' . $customer->getId();
        $data->authToken = $jwt;
        $data->customer = [
            'id'        => (int) $customer->getId(),
            'email'     => $customer->getEmail(),
            'firstName' => $customer->getFirstname(),
            'lastName'  => $customer->getLastname(),
        ];
        $data->cartMaskedId = $cartId;
        $data->cartItemsQty = $cartQty;
        $data->isNewCustomer = $isNewCustomer;

        // Clear sensitive input fields from the response
        $data->token = null;
        $data->maskedId = null;
        $data->password = null;

        return $data;
    }

    private function mergeGuestCart(string $maskedId, \Mage_Customer_Model_Customer $customer): array
    {
        /** @var \Mage_Sales_Model_Quote $guestCart */
        $guestCart = \Mage::getModel('sales/quote')->load($maskedId, 'masked_quote_id');
        if (!$guestCart->getId() || !$guestCart->getIsActive() || !$guestCart->getItemsCount()) {
            return ['maskedId' => null, 'qty' => 0];
        }
        // Only adopt a genuine guest cart from the current store. Without this a
        // leaked/guessed masked id could merge another customer's (or another
        // store's) cart into the authenticating customer's cart.
        if ($guestCart->getCustomerId()
            || (int) $guestCart->getStoreId() !== (int) \Mage::app()->getStore()->getId()
        ) {
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

        // Import customer default addresses so shipping quotes work on cart page
        $defaultShipping = $customer->getDefaultShippingAddress();
        if ($defaultShipping && $defaultShipping->getId()) {
            $shippingAddress = $customerCart->getShippingAddress();
            if (!$shippingAddress->getFirstname()) {
                $shippingAddress->importCustomerAddress($defaultShipping);
                $shippingAddress->setSaveInAddressBook(0);
            }
        }
        $defaultBilling = $customer->getDefaultBillingAddress();
        if ($defaultBilling && $defaultBilling->getId()) {
            $billingAddress = $customerCart->getBillingAddress();
            if (!$billingAddress->getFirstname()) {
                $billingAddress->importCustomerAddress($defaultBilling);
                $billingAddress->setSaveInAddressBook(0);
            }
        }

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
