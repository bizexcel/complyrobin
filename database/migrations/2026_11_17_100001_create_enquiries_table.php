<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Messages from the contact form on www.complyrobin.com (POST /api/enquiries). Platform-wide and read only by
        // staff: the tenant role, which serves the API, may add an enquiry but never read or change one.
        Schema::create('enquiries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email');
            $table->string('company')->nullable();
            $table->text('message');
            $table->timestampTz('handled_at')->nullable();
            $table->foreignUuid('handled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->index(['handled_at', 'created_at']);
        });

        DB::statement('revoke select, update, delete on enquiries from app_tenant');
    }

    public function down(): void
    {
        Schema::dropIfExists('enquiries');
    }
};
