<?php

namespace App\Modules\Credentials\Models;

use App\Core\Tenancy\Concerns\UsesTenancyConnection;
use App\Modules\Credentials\Enums\VendorCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A SaaS vendor from the global catalogue (Laravel Cloud, GitHub, Stripe…). Written only by VendorSeeder.
 *
 * @property string $slug
 * @property string $name
 * @property VendorCategory $category
 * @property string $website_url
 * @property string|null $rotation_url
 * @property int $rotation_days
 */
#[Fillable(['slug', 'name', 'category', 'website_url', 'rotation_url', 'rotation_days'])]
class Vendor extends Model
{
    use HasUuids, UsesTenancyConnection;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => VendorCategory::class,
            'rotation_days' => 'integer',
        ];
    }
}
