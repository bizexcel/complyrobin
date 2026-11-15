<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each time a credential was rotated: the evidence auditors ask for. Append-only for the app, and kept even if
        // the credential is deleted (restrict, not cascade).
        Schema::tenantTable('credential_rotations', function (Blueprint $table): void {
            $table->uuid('credential_id');
            $table->foreignUuid('rotated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('rotated_at');
            $table->timestampTz('due_at')->comment('when it was due, so on time or late can be worked out');
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->tenantForeign('credential_id', 'credentials');
            $table->index(['organisation_id', 'credential_id', 'rotated_at']);
        });

        DB::statement('revoke update, delete on credential_rotations from app_tenant');
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_rotations');
    }
};
