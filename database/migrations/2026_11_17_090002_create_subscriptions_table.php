<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Cashier's subscriptions table, owned by the root organisation: Schema::tenantTable() adds the id and the
        // organisation_id that Cashier calls the customer's foreign key. The type is the module key.
        Schema::tenantTable('subscriptions', function (Blueprint $table): void {
            $table->string('type');
            $table->string('airwallex_id')->unique();
            $table->string('airwallex_status');
            $table->string('airwallex_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->timestampsTz();

            $table->index(['organisation_id', 'airwallex_status']);
        });

        // A sub-organisation may also read its root's subscriptions, which it inherits.
        DB::statement(<<<'SQL'
            create policy subscriptions_root_rows on subscriptions for select
            using (organisation_id = (
                select root_id from organisations
                where id = nullif(current_setting('app.organisation_id', true), '')::uuid
            ))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
