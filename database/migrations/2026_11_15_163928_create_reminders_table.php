<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The send log: one row per reminder step per recipient for one due date. Its unique key is what makes each
        // reminder go out exactly once; a rotation gives the credential a new due date and so a fresh cycle.
        Schema::tenantTable('reminders', function (Blueprint $table): void {
            $table->uuid('credential_id');
            $table->uuid('person_id')->nullable()->comment('an owner or backup owner, who may have no login');
            $table->foreignUuid('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('audience');
            $table->smallInteger('offset_days');
            $table->timestampTz('due_at');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->tenantForeign('credential_id', 'credentials');
            $table->tenantForeign('person_id', 'people');
            $table->unique(['organisation_id', 'credential_id', 'due_at', 'kind', 'offset_days', 'person_id', 'user_id'], 'reminders_once_unique')->nullsNotDistinct();
            $table->index(['organisation_id', 'sent_at', 'created_at']);
        });

        DB::statement('alter table reminders add constraint reminders_one_recipient check (num_nonnulls(person_id, user_id) = 1)');
        DB::statement('revoke delete on reminders from app_tenant');
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
