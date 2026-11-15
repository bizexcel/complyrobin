<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // In-app (database) notifications. They belong to a user across organisations, so they carry only short text
        // and deep links. A user reads and marks only their own; anyone may send one.
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->uuidMorphs('notifiable');
            $table->jsonb('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
        });

        $own = "notifiable_id = nullif(current_setting('app.user_id', true), '')::uuid";

        DB::statement('alter table notifications enable row level security');
        DB::statement('alter table notifications force row level security');
        DB::statement('create policy notifications_send on notifications for insert with check (true)');
        DB::statement("create policy notifications_own_select on notifications for select using ({$own})");
        DB::statement("create policy notifications_own_update on notifications for update using ({$own}) with check ({$own})");
        DB::statement("create policy notifications_own_delete on notifications for delete using ({$own})");
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
