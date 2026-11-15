<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Staff and contacts. A person may also be a member (user_id) so reminders can reach them in the app.
        Schema::tenantTable('people', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('department_id')->nullable();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('job_title')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->tenantForeign('department_id', 'departments');
            $table->index(['organisation_id', 'name']);
        });

        DB::statement('create unique index people_user_unique on people (organisation_id, user_id) where user_id is not null and deleted_at is null');
    }

    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};
