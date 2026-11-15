<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->string('timezone')->default('Asia/Kuala_Lumpur')->after('slug');
            $table->jsonb('previous_slugs')->default('[]')->after('timezone');
        });

        // Old slugs redirect to the current one and can't be claimed by another organisation, so they are looked up often.
        DB::statement('create index organisations_previous_slugs_index on organisations using gin (previous_slugs)');
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->dropIndex('organisations_previous_slugs_index');
            $table->dropColumn(['timezone', 'previous_slugs']);
        });
    }
};
