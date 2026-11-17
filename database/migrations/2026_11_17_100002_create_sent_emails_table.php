<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every email the app sends: who to, the subject and which email it was. Never the body, which can hold sign-in
        // codes and invitation links. Platform-wide, so the tenant role may record a send but never read the log.
        Schema::create('sent_emails', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('to');
            $table->string('subject')->nullable();
            $table->string('kind')->nullable();
            $table->string('message_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('created_at');
        });

        DB::statement('revoke select, update, delete on sent_emails from app_tenant');
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_emails');
    }
};
