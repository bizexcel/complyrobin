<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The Airwallex webhook inbox: each verified event once, by its Airwallex event id. A platform table: an event arrives before
        // anyone knows which organisation it is for.
        Schema::create('billing_webhook_calls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('airwallex_id')->unique();
            $table->string('name');
            $table->jsonb('payload');
            $table->timestampTz('created_at');
        });

        // The webhook request runs as the app and may only add events, never read or change them. Duplicates are
        // skipped with "on conflict do nothing", which needs no more than INSERT. Staff read them as the platform.
        DB::statement('revoke select, update, delete on billing_webhook_calls from app_tenant');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_webhook_calls');
    }
};
