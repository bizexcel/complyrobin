<?php

namespace App\Core\Audit\Concerns;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Records every create, update and delete of the model in the current organisation's audit trail: who did it (the
 * signed-in user) and each changed attribute's old and new value. Hidden and encrypted attributes are never written.
 *
 * @mixin Model
 */
trait LogsTenantActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logExcept($this->attributesNeverLogged())
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return list<string>
     */
    protected function attributesNeverLogged(): array
    {
        $encrypted = array_keys(array_filter(
            $this->getCasts(),
            fn (string $cast): bool => str_starts_with($cast, 'encrypted'),
        ));

        return array_values(array_unique([...$this->getHidden(), ...$encrypted, 'organisation_id', 'created_at', 'updated_at']));
    }
}
