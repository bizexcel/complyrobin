<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A reminder can now also go to a team chat channel: still exactly one recipient per row.
        Schema::table('reminders', function (Blueprint $table): void {
            $table->uuid('notification_channel_id')->nullable()->after('user_id');
            $table->tenantForeign('notification_channel_id', 'notification_channels');
            $table->dropUnique('reminders_once_unique');
            $table->unique(
                ['organisation_id', 'credential_id', 'due_at', 'kind', 'offset_days', 'person_id', 'user_id', 'notification_channel_id'],
                'reminders_once_unique',
            )->nullsNotDistinct();
        });

        DB::statement('alter table reminders drop constraint reminders_one_recipient');
        DB::statement('alter table reminders add constraint reminders_one_recipient check (num_nonnulls(person_id, user_id, notification_channel_id) = 1)');
    }

    public function down(): void
    {
        DB::statement('alter table reminders drop constraint reminders_one_recipient');
        DB::statement('alter table reminders add constraint reminders_one_recipient check (num_nonnulls(person_id, user_id) = 1)');

        Schema::table('reminders', function (Blueprint $table): void {
            $table->dropUnique('reminders_once_unique');
            $table->dropForeign(['organisation_id', 'notification_channel_id']);
            $table->dropColumn('notification_channel_id');
            $table->unique(['organisation_id', 'credential_id', 'due_at', 'kind', 'offset_days', 'person_id', 'user_id'], 'reminders_once_unique')->nullsNotDistinct();
        });
    }
};
