<?php

namespace App\Core\Files\Models;

use App\Core\Audit\Concerns\LogsTenantActivity;
use App\Core\Tenancy\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

/**
 * An uploaded file, owned by one organisation and stored under its own folder.
 */
class Media extends SpatieMedia
{
    use BelongsToOrganisation, HasUuids, LogsTenantActivity;
}
