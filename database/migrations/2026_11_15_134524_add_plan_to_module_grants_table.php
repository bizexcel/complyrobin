<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_grants', function (Blueprint $table): void {
            // Only for the credentials module: team (the default when null) or enterprise.
            $table->string('plan')->nullable()->after('module');
        });
    }

    public function down(): void
    {
        Schema::table('module_grants', function (Blueprint $table): void {
            $table->dropColumn('plan');
        });
    }
};
