<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which users belong to which organisation. One user can belong to several.
        Schema::tenantTable('organisation_user', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['organisation_id', 'user_id']);
            $table->index('user_id');
        });

        // Besides the current organisation's rows, a signed-in user may read their own memberships: that is how the
        // organisation switcher and the tenant check work before an organisation is chosen. Read-only: joining or
        // leaving still happens inside the organisation.
        DB::statement(<<<'SQL'
            create policy organisation_user_own_rows on organisation_user for select
            using (user_id = nullif(current_setting('app.user_id', true), '')::uuid)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_user');
    }
};
