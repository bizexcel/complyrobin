<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The billable model is the root organisation, so Cashier's customer columns live here.
        Schema::table('organisations', function (Blueprint $table): void {
            $table->string('airwallex_id')->nullable()->index();
            $table->timestampTz('trial_ends_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->dropIndex([
                'airwallex_id',
            ]);

            $table->dropColumn([
                'airwallex_id',
                'trial_ends_at',
            ]);
        });
    }
};
