<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How a runner rotates one credential: where it makes the key and where it puts it. Ids only, never a key or
        // a token. One per credential.
        Schema::tenantTable('automatic_rotations', function (Blueprint $table): void {
            $table->uuid('credential_id');
            $table->uuid('runner_id');
            $table->string('source');
            $table->jsonb('source_settings');
            $table->jsonb('destinations');
            $table->timestampTz('requested_at')->nullable()->comment('someone pressed Rotate now');
            $table->timestampsTz();

            $table->unique(['organisation_id', 'credential_id']);
            $table->tenantForeign('credential_id', 'credentials', 'cascade');
            $table->tenantForeign('runner_id', 'runners');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automatic_rotations');
    }
};
