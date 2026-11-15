<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::tenantTable('invitations', function (Blueprint $table): void {
            $table->string('email');
            $table->string('role');
            $table->string('token', 64)->unique()->comment('sha-256 of the token in the link; the link itself is never stored');
            $table->foreignUuid('invited_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampsTz();
        });

        // One open invitation per person per organisation: inviting again replaces it.
        DB::statement('create unique index invitations_pending_email_unique on invitations (organisation_id, email) where accepted_at is null');
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
