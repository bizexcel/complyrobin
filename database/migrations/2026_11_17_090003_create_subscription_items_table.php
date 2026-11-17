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
        Schema::tenantTable('subscription_items', function (Blueprint $table): void {
            $table->uuid('subscription_id');
            $table->tenantForeign('subscription_id', 'subscriptions', 'cascade');
            $table->string('airwallex_id')->unique();
            $table->string('airwallex_product');
            $table->string('airwallex_price');
            $table->integer('quantity')->nullable();
            $table->timestampsTz();

            $table->index(['subscription_id', 'airwallex_price']);
        });

        // A sub-organisation may also read its root's subscription items, which it inherits.
        DB::statement(<<<'SQL'
            create policy subscription_items_root_rows on subscription_items for select
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
        Schema::dropIfExists('subscription_items');
    }
};
