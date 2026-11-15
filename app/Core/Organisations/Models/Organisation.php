<?php

namespace App\Core\Organisations\Models;

use Database\Factories\OrganisationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant. Sub-organisations point at their parent; billing resolves through root_id.
 * Not row-level-security scoped itself: access is decided by memberships.
 */
#[Fillable(['name', 'slug', 'parent_id', 'root_id', 'settings'])]
#[UseFactory(OrganisationFactory::class)]
class Organisation extends Model
{
    /** @use HasFactory<OrganisationFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }
}
