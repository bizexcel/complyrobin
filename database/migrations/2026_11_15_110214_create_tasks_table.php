<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Follow-up work, optionally about another record (a credential, later a risk or a non-conformity).
        Schema::tenantTable('tasks', function (Blueprint $table): void {
            $table->nullableUuidMorphs('taskable');
            $table->string('title');
            $table->text('notes')->nullable();
            $table->uuid('assignee_id')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->string('status')->default('open');
            $table->timestampTz('completed_at')->nullable();
            $table->foreignUuid('completed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->tenantForeign('assignee_id', 'people');
            $table->index(['organisation_id', 'status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
