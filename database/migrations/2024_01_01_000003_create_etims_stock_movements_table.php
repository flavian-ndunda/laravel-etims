<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create eTIMS stock movements audit table.
 *
 * Tracks every stock movement reported to KRA: purchases, sales-driven
 * reductions, adjustments, transfers, imports, and exports.
 *
 * This table is your compliance record. A well-maintained movements table
 * should reconcile exactly with your invoice table — every line item sold
 * on a fiscal invoice should have a corresponding movement of type '02' (Sale).
 *
 * Key columns:
 *   movement_type       → KRA stockIOTyCd code (01-09)
 *   movement_type_label → Human-readable (Purchase, Sale, Adjustment, etc.)
 *   reference_number    → PO number, invoice number, or transfer reference
 *   status              → pending | recorded | failed
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('etims.tables.stock_movements', 'etims_stock_movements');

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();

            $table->string('item_code', 100)->index();
            $table->string('movement_type', 10);        // KRA code: 01-09
            $table->string('movement_type_label', 50);  // Human: Purchase, Sale, etc.

            $table->decimal('quantity', 15, 4);
            $table->string('unit_of_measure', 10)->default('EA');
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('total_amount', 15, 4)->default(0);

            $table->date('movement_date')->index();
            $table->string('reference_number', 100)->nullable()->index();

            $table->enum('status', ['pending', 'processing', 'recorded', 'failed'])
                ->default('pending')
                ->index();

            $table->json('payload');
            $table->json('response')->nullable();
            $table->text('failure_reason')->nullable();

            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();

            $table->string('tenant_id', 100)->nullable()->index();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();

            // Indexes for compliance reporting queries
            $table->index(['movement_type', 'status', 'tenant_id']);
            $table->index(['item_code', 'movement_date', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('etims.tables.stock_movements', 'etims_stock_movements'));
    }
};
