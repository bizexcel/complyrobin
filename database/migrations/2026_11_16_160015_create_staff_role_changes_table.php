<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who was made ComplyRobin staff, given another staff role or removed, by whom and when. Platform-wide, so not a
        // tenant table. Append-only: the platform role may add entries but never change or delete them, and the
        // customer app can't see them at all. Kept when an account is deleted (the ids become null).
        Schema::create('staff_role_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('changed_by_id')->nullable()->comment('null: from the server console')->constrained('users')->nullOnDelete();
            $table->string('from_role')->nullable();
            $table->string('to_role')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('created_at');
        });

        DB::statement('revoke all on staff_role_changes from app_tenant');
        DB::statement('revoke update, delete on staff_role_changes from app_platform');
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_role_changes');
    }
};
