<?php

namespace App\Modules\Credentials\Enums;

/**
 * Where a rotation runner makes new keys.
 */
enum RotationSource: string
{
    case OpenAi = 'openai';

    public function label(): string
    {
        return match ($this) {
            self::OpenAi => 'OpenAI',
        };
    }
}
