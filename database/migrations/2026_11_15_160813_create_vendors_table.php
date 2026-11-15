<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The catalogue of SaaS vendors with a "where to rotate" link and a suggested interval. Global and written only
        // by VendorSeeder: the tenant role may read it.
        Schema::create('vendors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 60)->unique();
            $table->string('name');
            $table->string('category');
            $table->string('website_url');
            $table->string('rotation_url')->nullable();
            $table->smallInteger('rotation_days');
            $table->timestampsTz();
        });

        DB::statement('revoke insert, update, delete on vendors from app_tenant');
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
