<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create eTIMS invoices audit table.
 *
 * This table is the audit trail for all invoice submissions to KRA.
 * It supports:
 *   - Idempotency checks (unique idempotency_key)
 *   - Failed invoice recovery (status='failed' + failure_reason)
 *   - Compliance auditing (full payload + response stored)
 *   - Multi-tenancy (tenant_id column)
 *   - Admin dashboards (indexed status + invoice_number)
 *   - Receipt data (receipt_number, qr_code for printing)
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('etims.tables.invoices', 'etims_invoices');

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();

            // Invoice identification
            $table->string('invoice_number', 100);
            $table->string('idempotency_key', 64)->unique(); // md5 hash — prevents duplicate submissions

            // Submission lifecycle status
            // pending → processing → submitted (success path)
            //        ↘ failed (error path)
            //          ↘ retrying (re-queued)
            $table->enum('status', ['pending', 'processing', 'submitted', 'failed', 'retrying'])
                ->default('pending')
                ->index();

            // Full payload sent to KRA — essential for debugging and resubmission
            $table->json('payload');

            // KRA's response — only populated after submission attempt
            $table->json('response')->nullable();

            // KRA receipt data — populated on successful submission
            $table->string('receipt_number', 100)->nullable()->index();
            $table->text('qr_code')->nullable(); // QR code string or URL for printing

            // Failure details
            $table->text('failure_reason')->nullable();
            $table->timestamp('exhausted_at')->nullable(); // when all retries ran out

            // Retry tracking
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();

            // Multi-tenancy support — nullable for single-tenant deployments
            $table->string('tenant_id', 100)->nullable()->index();

            // Timestamps
            $table->timestamp('submitted_at')->nullable(); // when KRA accepted it
            $table->timestamps();

            // Composite index for common dashboard queries
            $table->index(['status', 'tenant_id', 'created_at']);
            $table->index(['invoice_number', 'tenant_id']);
        });
    }

    public function down(): void
    {
        $tableName = config('etims.tables.invoices', 'etims_invoices');
        Schema::dropIfExists($tableName);
    }
};
