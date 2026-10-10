<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payroll_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payroll_run_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('employee_id')
                ->constrained()
                ->restrictOnDelete();

            // Compensation snapshot used for this computation
            $table->decimal('basic_salary', 15, 2);
            $table->string('pay_frequency', 30);
            $table->string('currency', 3)->default('PHP');

            // Computed totals
            $table->decimal('gross_pay', 15, 2)->default(0);
            $table->decimal('total_deductions', 15, 2)->default(0);
            $table->decimal('net_pay', 15, 2)->default(0);

            $table->string('status', 30)
                ->default('draft')
                ->index();

            $table->timestamp('calculated_at')->nullable();

            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->unique(
                ['payroll_run_id', 'employee_id'],
                'uq_payroll_entry_run_employee'
            );

            $table->index(
                ['employee_id', 'payroll_run_id'],
                'idx_payroll_entries_employee_run'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_entries');
    }
};
