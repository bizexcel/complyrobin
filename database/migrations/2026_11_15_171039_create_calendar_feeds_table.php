<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A member's private calendar link (.ics) of upcoming rotations, one per member per organisation. Only a hash
        // of the link's token is stored; a new link replaces the row and revoking deletes it.
        Schema::tenantTable('calendar_feeds', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();

            $table->unique(['organisation_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_feeds');
    }
};
