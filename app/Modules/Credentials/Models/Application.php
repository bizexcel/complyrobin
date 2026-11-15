<?php

namespace App\Modules\Credentials\Models;

use App\Core\Audit\Concerns\LogsTenantActivity;
use App\Core\People\Models\Person;
use App\Core\Tenancy\Concerns\BelongsToOrganisation;
use App\Modules\Credentials\Enums\Criticality;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A SaaS application the organisation uses (Laravel Cloud, GitHub…), with the people responsible for it.
 *
 * @property string $name
 * @property Criticality $criticality
 * @property string|null $owner_id
 * @property string|null $backup_owner_id
 * @property string|null $console_url
 * @property Vendor|null $vendor
 */
#[Fillable(['vendor_id', 'name', 'criticality', 'owner_id', 'backup_owner_id', 'console_url', 'notes'])]
#[UseFactory(ApplicationFactory::class)]
class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use BelongsToOrganisation, HasFactory, HasUuids, LogsTenantActivity, SoftDeletes;

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function backupOwner(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'criticality' => Criticality::class,
        ];
    }
}
