<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The organisation's team chat channels for reminders (Team). Settings hold the webhook URL, encrypted, and are
        // never shown back or written to the audit trail. A channel gets reminders only after a test message succeeds.
        Schema::tenantTable('notification_channels', function (Blueprint $table): void {
            $table->string('type');
            $table->string('name');
            $table->text('settings');
            $table->boolean('is_enabled')->default(true);
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channels');
    }
};
