<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug', 40)->unique();
            $table->uuid('parent_id')->nullable()->index();
            $table->uuid('root_id')->nullable()->index();
            $table->jsonb('settings')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        // Self-references are added once the primary key exists.
        Schema::table('organisations', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('organisations')->nullOnDelete();
            $table->foreign('root_id')->references('id')->on('organisations')->nullOnDelete();
        });

        // The tenant role may read organisations (memberships decide access) and create one at
        // sign-up, but may only update its own row and may never delete: there is no DELETE
        // policy, so a delete from the tenant role matches nothing. Platform work bypasses RLS.
        $own = "id = nullif(current_setting('app.organisation_id', true), '')::uuid";

        DB::statement('alter table organisations enable row level security');
        DB::statement('alter table organisations force row level security');
        DB::statement('create policy organisations_select on organisations for select using (true)');
        DB::statement('create policy organisations_insert on organisations for insert with check (true)');
        DB::statement("create policy organisations_update on organisations for update using ({$own}) with check ({$own})");
    }

    public function down(): void
    {
        Schema::dropIfExists('organisations');
    }
};
