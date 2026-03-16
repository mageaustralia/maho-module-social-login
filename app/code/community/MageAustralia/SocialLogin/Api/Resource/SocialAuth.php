<?php

declare(strict_types=1);

namespace MageAustralia\SocialLogin\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use MageAustralia\SocialLogin\Api\State\Processor\SocialAuthProcessor;

#[ApiResource(
    shortName: 'SocialAuth',
    description: 'Authenticate or register a customer via social login (Google/Apple/Facebook)',
    processor: SocialAuthProcessor::class,
    operations: [
        new Post(
            uriTemplate: '/customers/social-auth',
            description: 'Verify a social provider token and return a Maho JWT',
            security: "true",
        ),
    ],
)]
class SocialAuth
{
    #[ApiProperty(identifier: true, writable: false)]
    public ?string $id = null;

    /** Social provider: 'google', 'apple', or 'facebook' */
    public ?string $provider = null;

    /** ID token (Google/Apple) or access token (Facebook) */
    public ?string $token = null;

    /** Optional guest cart masked ID to merge after login */
    public ?string $maskedId = null;

    /** Password to verify account ownership when linking to existing customer */
    public ?string $password = null;

    /** Maho JWT (output) */
    #[ApiProperty(writable: false)]
    public ?string $authToken = null;

    /** Customer data (output) */
    #[ApiProperty(writable: false)]
    public ?array $customer = null;

    /** Customer cart masked ID (output) */
    #[ApiProperty(writable: false)]
    public ?string $cartMaskedId = null;

    /** Cart item count (output) */
    #[ApiProperty(writable: false)]
    public ?int $cartItemsQty = null;

    /** Whether a new customer was created (output) */
    #[ApiProperty(writable: false)]
    public ?bool $isNewCustomer = null;

    /** Set when existing account found but not yet linked — client must provide password or use email link (output) */
    #[ApiProperty(writable: false)]
    public ?string $linkRequired = null;
}
