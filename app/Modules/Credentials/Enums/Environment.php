<?php

namespace App\Modules\Credentials\Enums;

enum Environment: string
{
    case Production = 'production';
    case Staging = 'staging';
    case Development = 'development';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
