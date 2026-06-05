<?php

declare(strict_types=1);

namespace MageAustralia\SocialLogin\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use MageAustralia\SocialLogin\Api\State\Processor\OtpRequestProcessor;

#[ApiResource(
    shortName: 'OtpRequest',
    description: 'Request a one-time verification code (login/register/link) via email or SMS',
    processor: OtpRequestProcessor::class,
    operations: [
        new Post(
            uriTemplate: '/customers/otp/request',
            description: 'Send a one-time code. Always returns a uniform response (enumeration-safe).',
            security: "true",
        ),
    ],
)]
class OtpRequest
{
    #[ApiProperty(identifier: true, writable: false)]
    public ?string $id = null;

    /** Email address or mobile number the code is sent to (ignored for purpose=link) */
    public ?string $identifier = null;

    /** Purpose of the code: 'login', 'register', or 'link' */
    public ?string $purpose = null;

    /** Delivery channel: 'email' or 'sms' (ignored for purpose=link) */
    public ?string $channel = null;

    /** Social provider (only used when purpose=link): 'google', 'apple', or 'facebook' */
    public ?string $provider = null;

    /** Provider token (only used when purpose=link) */
    public ?string $token = null;

    /** Whether the request was accepted (output) */
    #[ApiProperty(writable: false)]
    public ?bool $requested = null;

    /** Uniform status message (output) */
    #[ApiProperty(writable: false)]
    public ?string $message = null;
}
