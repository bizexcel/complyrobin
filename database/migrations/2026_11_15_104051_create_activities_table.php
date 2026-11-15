<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * spatie/laravel-activitylog's table (named `activities` after our Activity model), kept per organisation: who changed which record, from what to what.
 * Append-only for the app: the tenant role may add entries but never change or delete them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::tenantTable('activities', function (Blueprint $table): void {
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableUuidMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableUuidMorphs('causer', 'causer');
            $table->jsonb('attribute_changes')->nullable();
            $table->jsonb('properties')->nullable();
            $table->timestampsTz();

            $table->index(['organisation_id', 'created_at']);
        });

        DB::statement('revoke update, delete on activities from app_tenant');
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
