<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The last number issued in each reference series (e.g. CRD-0042, or INC-2026-0007 per year).
        Schema::tenantTable('counters', function (Blueprint $table): void {
            $table->string('name');
            $table->smallInteger('year')->nullable()->comment('null for series that never restart');
            $table->integer('value')->default(0);
            $table->timestampsTz();

            $table->unique(['organisation_id', 'name', 'year'])->nullsNotDistinct();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counters');
    }
};
