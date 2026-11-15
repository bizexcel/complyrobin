<?php

namespace App\Core\Entitlements\Enums;

/**
 * What an organisation pays for. Limits and features live in config/plans.php so they can change without code.
 */
enum Plan: string
{
    case Free = 'free';
    case Team = 'team';
    case Enterprise = 'enterprise';

    public function allows(Feature $feature): bool
    {
        return in_array($feature->value, (array) config("plans.{$this->value}.features", []), true);
    }

    /**
     * The most allowed, or null for unlimited.
     */
    public function limit(Limit $limit): ?int
    {
        $value = config("plans.{$this->value}.limits.{$limit->value}");

        return is_int($value) ? $value : null;
    }
}
