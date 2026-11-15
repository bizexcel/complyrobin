<?php

namespace App\Core\Tenancy\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent remembers the connection a model was loaded or created on. Tenancy decides the connection instead: the
 * tenant role normally, the platform role inside Tenancy::bypass(). Without this, a model loaded in one and saved in
 * the other would write as the wrong role: blocked silently by row-level security, or worse, skipping it.
 * To work across organisations use Tenancy::bypass(), never Model::on().
 *
 * @mixin Model
 */
trait UsesTenancyConnection
{
    public function getConnectionName(): ?string
    {
        return null;
    }
}
