<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rotation runners: programs the organisation runs itself, which swap keys and report back. Only a hash of each
        // runner's token is stored. Revoked, never deleted, so the runs they made keep pointing at them.
        Schema::tenantTable('runners', function (Blueprint $table): void {
            $table->string('name', 80);
            $table->string('token', 64)->unique()->comment("sha-256 of the runner's token");
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->unique(['organisation_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runners');
    }
};
