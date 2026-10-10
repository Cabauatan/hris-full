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
        Schema::create('leave_credit_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('leave_type_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('leave_request_id')
                ->nullable()
                ->constrained('leave_requests')
                ->nullOnDelete();

            $table->unsignedSmallInteger('year');

            // Positive = credit, Negative = deduction
            $table->decimal('units', 10, 2);

            $table->string('transaction_type', 50);

            $table->date('effective_date');

            $table->text('remarks')->nullable();

            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(
                ['employee_id', 'leave_type_id', 'year', 'effective_date'],
                'idx_leave_credit_employee_type_year_date'
            );

            $table->index(
                ['leave_request_id'],
                'idx_leave_credit_request'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_credit_transactions');
    }
};
