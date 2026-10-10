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
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payroll_period_id')
                ->constrained()
                ->restrictOnDelete();

            $table->unsignedInteger('run_number')->default(1);

            $table->string('status', 30)
                ->default('draft')
                ->index();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('finalized_at')->nullable();

            $table->foreignId('finalized_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('remarks')->nullable();

            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(
                ['payroll_period_id', 'run_number'],
                'uq_payroll_run_period_number'
            );

            $table->index(
                ['payroll_period_id', 'status'],
                'idx_payroll_runs_period_status'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
