<?php

namespace App\Modules\Credentials\Enums;

enum VendorCategory: string
{
    case Hosting = 'hosting';
    case Cloud = 'cloud';
    case SourceControl = 'source_control';
    case Payments = 'payments';
    case Email = 'email';
    case Communication = 'communication';
    case Productivity = 'productivity';
    case Ai = 'ai';
    case Security = 'security';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::SourceControl => 'Source control',
            self::Ai => 'AI',
            default => $this->name,
        };
    }
}
