<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * spatie/laravel-permission in teams mode, with the organisation as the team and uuid keys throughout.
 *
 * Permissions are global and defined in code (App\Core\Access\Enums\Ability); only the seeder writes them. Roles belong
 * to one organisation. The three pivots carry organisation_id with row-level security, and reference roles through
 * composite keys, so the database refuses to pair a role with another organisation's members or permissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('guard_name');
            $table->timestampsTz();

            $table->unique(['name', 'guard_name']);
        });

        DB::statement('revoke insert, update, delete on permissions from app_tenant');

        Schema::tenantTable('roles', function (Blueprint $table): void {
            $table->string('name');
            $table->string('guard_name');
            $table->timestampsTz();

            $table->unique(['organisation_id', 'name', 'guard_name']);
        });

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->uuid('role_id');
            $table->uuidMorphs('model');

            $table->tenantForeign('role_id', 'roles', 'cascade');
            $table->primary(['organisation_id', 'role_id', 'model_id', 'model_type']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('permission_id')->constrained()->cascadeOnDelete();
            $table->uuidMorphs('model');

            $table->primary(['organisation_id', 'permission_id', 'model_id', 'model_type']);
        });

        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->foreignUuid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('permission_id')->constrained()->cascadeOnDelete();
            $table->uuid('role_id');

            $table->tenantForeign('role_id', 'roles', 'cascade');
            $table->primary(['permission_id', 'role_id']);
        });

        foreach (['model_has_roles', 'model_has_permissions', 'role_has_permissions'] as $pivot) {
            Schema::tenantIsolation($pivot);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
