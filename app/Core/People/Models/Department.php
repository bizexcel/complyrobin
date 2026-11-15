<?php

namespace App\Core\People\Models;

use App\Core\Tenancy\Concerns\BelongsToOrganisation;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'description', 'parent_id'])]
#[UseFactory(DepartmentFactory::class)]
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use BelongsToOrganisation, HasFactory, HasUuids, SoftDeletes;
}
