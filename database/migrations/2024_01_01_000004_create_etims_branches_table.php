<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create eTIMS branches table.
 *
 * Tracks every branch registered with KRA and their current status.
 * Each branch must be registered before it can submit invoices or
 * sync stock under its branch ID (bhfId).
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('etims.tables.branches', 'etims_branches');

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();

            $table->string('branch_id', 10)->index();    // KRA bhfId e.g. '00', '01'
            $table->string('branch_name', 200);
            $table->string('branch_address', 500);
            $table->string('manager_name', 200)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 200)->nullable();

            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active')->index();
            $table->string('kra_status', 5)->nullable();   // KRA status code 01/02/03

            $table->json('payload');
            $table->json('response')->nullable();
            $table->text('failure_reason')->nullable();

            $table->string('tenant_id', 100)->nullable()->index();
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('etims.tables.branches', 'etims_branches'));
    }
};
