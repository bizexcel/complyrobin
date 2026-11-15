<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::tenantTable('departments', function (Blueprint $table): void {
            $table->string('name');
            $table->text('description')->nullable();
            $table->uuid('parent_id')->nullable();
            $table->tenantForeign('parent_id', 'departments');
            $table->timestampsTz();
            $table->softDeletesTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
