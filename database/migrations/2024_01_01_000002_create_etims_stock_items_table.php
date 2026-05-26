<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create eTIMS stock items audit table.
 *
 * Tracks every attempt to register an item master record with KRA.
 * KRA requires all sellable items to be registered before they can
 * appear on a fiscal invoice — this table is your registration audit trail.
 *
 * Key columns:
 *   item_code     → Your internal SKU/product code
 *   kra_item_code → KRA's registered code (populated after successful sync)
 *   status        → pending | synced | failed
 *   payload       → Full payload sent to KRA (for debugging and resubmission)
 *   response      → KRA's raw response
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('etims.tables.stock_items', 'etims_stock_items');

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();

            $table->string('item_code', 100)->index();
            $table->string('kra_item_code', 100)->nullable(); // populated after successful sync
            $table->string('item_name', 200);

            $table->enum('status', ['pending', 'processing', 'synced', 'failed'])
                ->default('pending')
                ->index();

            $table->json('payload');
            $table->json('response')->nullable();
            $table->text('failure_reason')->nullable();

            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();

            $table->string('tenant_id', 100)->nullable()->index();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'tenant_id']);
            $table->index(['item_code', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('etims.tables.stock_items', 'etims_stock_items'));
    }
};
