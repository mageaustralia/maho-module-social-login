<?php

declare(strict_types=1);

namespace MageAustralia\SocialLogin\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use MageAustralia\SocialLogin\Api\State\Processor\OtpVerifyProcessor;

#[ApiResource(
    shortName: 'OtpVerify',
    description: 'Verify a one-time code (login/register/link) and return a Maho JWT',
    processor: OtpVerifyProcessor::class,
    operations: [
        new Post(
            uriTemplate: '/customers/otp/verify',
            description: 'Verify a one-time code and return a Maho JWT for the storefront session',
            security: "true",
        ),
    ],
)]
class OtpVerify
{
    #[ApiProperty(identifier: true, writable: false)]
    public ?string $id = null;

    /** Email address or mobile number the code was sent to */
    public ?string $identifier = null;

    /** Purpose of the code: 'login', 'register', or 'link' */
    public ?string $purpose = null;

    /** The one-time code entered by the customer */
    public ?string $code = null;

    /** Social provider (only used when purpose=link): 'google', 'apple', or 'facebook' */
    public ?string $provider = null;

    /** Provider token (only used when purpose=link) */
    public ?string $token = null;

    /** First name for the new account (only used when purpose=register) */
    public ?string $firstName = null;

    /** Last name for the new account (only used when purpose=register) */
    public ?string $lastName = null;

    /** Optional guest cart masked ID to merge after sign-in */
    public ?string $maskedId = null;

    /** Maho JWT (output) */
    #[ApiProperty(writable: false)]
    public ?string $authToken = null;

    /** Customer data (output) */
    #[ApiProperty(writable: false)]
    public ?array $customer = null;

    /** Whether a new customer was created (output) */
    #[ApiProperty(writable: false)]
    public ?bool $isNewCustomer = null;

    /** Customer cart masked ID (output) */
    #[ApiProperty(writable: false)]
    public ?string $cartMaskedId = null;

    /** Cart item count (output) */
    #[ApiProperty(writable: false)]
    public ?int $cartItemsQty = null;
}
