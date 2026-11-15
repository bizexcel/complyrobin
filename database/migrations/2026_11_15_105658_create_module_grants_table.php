<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Modules granted by platform staff (enterprise deals, invoices, trials), on a root organisation.
        // Paid subscriptions are separate (billing).
        Schema::tenantTable('module_grants', function (Blueprint $table): void {
            $table->string('module');
            $table->foreignUuid('granted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->index(['organisation_id', 'module']);
        });

        // Only platform staff grant modules. A sub-organisation may also read its root's grants, which it inherits.
        DB::statement('revoke insert, update, delete on module_grants from app_tenant');
        DB::statement(<<<'SQL'
            create policy module_grants_root_rows on module_grants for select
            using (organisation_id = (
                select root_id from organisations
                where id = nullif(current_setting('app.organisation_id', true), '')::uuid
            ))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('module_grants');
    }
};
