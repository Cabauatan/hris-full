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
        Schema::create('cto_credit_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained()
                ->cascadeOnDelete();

            // Source when CTO credit came from approved overtime
            $table->foreignId('overtime_request_id')
                ->nullable()
                ->constrained('overtime_requests')
                ->nullOnDelete();

            // Source when CTO credit was consumed by a CTO request
            $table->foreignId('cto_request_id')
                ->nullable()
                ->constrained('cto_requests')
                ->nullOnDelete();

            // Positive = credit, Negative = debit
            $table->integer('minutes');

            $table->string('transaction_type', 50);

            $table->date('effective_date');

            // Optional expiration of earned CTO credits
            $table->date('expires_at')->nullable();

            $table->text('remarks')->nullable();

            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(
                ['employee_id', 'effective_date'],
                'idx_cto_credit_employee_effective'
            );

            $table->index(
                ['employee_id', 'expires_at'],
                'idx_cto_credit_employee_expiry'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cto_credit_transactions');
    }
};
