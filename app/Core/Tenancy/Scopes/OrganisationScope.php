<?php

namespace App\Core\Tenancy\Scopes;

use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits every query to the current organisation. Throws when none is set (fails closed).
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
class OrganisationScope implements Scope
{
    /**
     * @param  Builder<covariant TModel>  $builder
     * @param  TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (Tenancy::isBypassing()) {
            return;
        }

        $builder->where($model->qualifyColumn('organisation_id'), Tenancy::id());
    }
}
