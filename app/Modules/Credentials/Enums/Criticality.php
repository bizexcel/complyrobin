<?php

namespace App\Modules\Credentials\Enums;

/**
 * How much the business depends on an application.
 */
enum Criticality: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
