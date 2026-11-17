<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each time a runner claimed a credential to rotate, and how it went. A success links to the rotation it
        // recorded. Kept when the credential is deleted (restrict).
        Schema::tenantTable('rotation_runs', function (Blueprint $table): void {
            $table->uuid('credential_id');
            $table->uuid('runner_id');
            $table->string('status');
            $table->timestampTz('claimed_at');
            $table->timestampTz('finished_at')->nullable();
            $table->uuid('credential_rotation_id')->nullable();
            $table->string('new_key_id', 100)->nullable()->comment("the vendor's id for the new key, never the key");
            $table->string('new_key_hint', 8)->nullable();
            $table->timestampTz('old_keys_expire_at')->nullable();
            $table->jsonb('deployments')->nullable();
            $table->string('failed_step', 20)->nullable();
            $table->text('failure')->nullable();

            $table->tenantForeign('credential_id', 'credentials');
            $table->tenantForeign('runner_id', 'runners');
            $table->tenantForeign('credential_rotation_id', 'credential_rotations');
            $table->index(['organisation_id', 'credential_id', 'claimed_at']);
        });

        // A credential has at most one run in progress.
        DB::statement("create unique index rotation_runs_one_open on rotation_runs (organisation_id, credential_id) where status = 'claimed'");
    }

    public function down(): void
    {
        Schema::dropIfExists('rotation_runs');
    }
};
