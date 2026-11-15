<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A SaaS application this organisation uses, from the vendor catalogue or its own.
        Schema::tenantTable('applications', function (Blueprint $table): void {
            $table->foreignUuid('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('criticality');
            $table->uuid('owner_id')->nullable();
            $table->uuid('backup_owner_id')->nullable();
            $table->string('console_url', 2048)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->tenantForeign('owner_id', 'people');
            $table->tenantForeign('backup_owner_id', 'people');
        });

        DB::statement('create unique index applications_name_unique on applications (organisation_id, lower(name)) where deleted_at is null');
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
