<?php

declare(strict_types=1);

namespace MageAustralia\SocialLogin\Api\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use MageAustralia\SocialLogin\Api\Resource\OtpVerify;
use Maho\ApiPlatform\Service\JwtService;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Headless (storefront) OTP verification: verifies a one-time code for login,
 * register, or link, then mints a Maho JWT and merges the guest cart. Normal
 * Maho frontend OTP flows go through MageAustralia_SocialLogin_OtpController.
 * Failures throw a generic BadRequest so a caller cannot distinguish reasons.
 */
class OtpVerifyProcessor implements ProcessorInterface
{
    /**
     * @param OtpVerify $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): OtpVerify
    {
        $purpose = $data->purpose ?: 'login';
        $storeId = (int) \Mage::app()->getStore()->getId();

        /** @var \Mage_Customer_Model_Customer|null $customer */
        $customer = null;
        $isNew = false;

        if ($purpose === 'login') {
            $email = \Mage::helper('sociallogin')->normaliseEmail((string) ($data->identifier ?? ''));
            $res = \Mage::helper('sociallogin/otp')->verifyCode($email, 'login', (string) $data->code, $storeId);
            if (empty($res['ok'])) {
                throw new BadRequestHttpException('Invalid or expired code.');
            }
            $customer = \Mage::getModel('customer/customer')
                ->setWebsiteId((int) \Mage::app()->getStore()->getWebsiteId())
                ->loadByEmail($email);
            if (!$customer->getId()) {
                throw new BadRequestHttpException('Could not sign in.');
            }
        } elseif ($purpose === 'register') {
            $email = \Mage::helper('sociallogin')->normaliseEmail((string) ($data->identifier ?? ''));
            $res = \Mage::helper('sociallogin/otp')->verifyCode($email, 'register', (string) $data->code, $storeId);
            if (empty($res['ok'])) {
                throw new BadRequestHttpException('Invalid or expired code.');
            }
            $existing = \Mage::getModel('customer/customer')
                ->setWebsiteId((int) \Mage::app()->getStore()->getWebsiteId())
                ->loadByEmail($email);
            if ($existing->getId()) {
                throw new BadRequestHttpException('An account already exists. Please sign in.');
            }
            $customer = \Mage::helper('sociallogin')->createCustomer([
                'email'       => $email,
                'given_name'  => (string) ($data->firstName ?? ''),
                'family_name' => (string) ($data->lastName ?? ''),
            ]);
            $isNew = true;
        } elseif ($purpose === 'link') {
            $res = \Mage::helper('sociallogin')->completeOtpLink(
                (string) ($data->provider ?? ''),
                (string) ($data->token ?? ''),
                (string) $data->code,
                $storeId,
            );
            if (empty($res['ok']) || empty($res['customer'])) {
                throw new BadRequestHttpException('Invalid or expired code.');
            }
            $customer = $res['customer'];
        } else {
            throw new BadRequestHttpException('Unsupported purpose.');
        }

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

        $data->id = 'otp-verify-' . $customer->getId();
        $data->authToken = $jwt;
        $data->customer = [
            'id'        => (int) $customer->getId(),
            'email'     => $customer->getEmail(),
            'firstName' => $customer->getFirstname(),
            'lastName'  => $customer->getLastname(),
        ];
        $data->isNewCustomer = $isNew;
        $data->cartMaskedId = $cartId;
        $data->cartItemsQty = $cartQty;

        // Clear sensitive input fields from the response
        $data->code = null;
        $data->token = null;
        $data->maskedId = null;

        return $data;
    }

    private function mergeGuestCart(string $maskedId, \Mage_Customer_Model_Customer $customer): array
    {
        /** @var \Mage_Sales_Model_Quote $guestCart */
        $guestCart = \Mage::getModel('sales/quote')->load($maskedId, 'masked_quote_id');
        if (!$guestCart->getId() || !$guestCart->getIsActive() || !$guestCart->getItemsCount()) {
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
