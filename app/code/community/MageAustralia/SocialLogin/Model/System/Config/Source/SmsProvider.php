<?php

declare(strict_types=1);

class MageAustralia_SocialLogin_Model_System_Config_Source_SmsProvider
{
    /** @return array<int, array{value: string, label: string}> */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'clickatell', 'label' => 'Clickatell'],
        ];
    }
}
