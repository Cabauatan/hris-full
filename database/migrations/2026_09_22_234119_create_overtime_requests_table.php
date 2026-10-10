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
        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained()
                ->cascadeOnDelete();

            // Requested OT coverage
            $table->dateTime('start_at');
            $table->dateTime('end_at');

            // Backend-calculated duration
            $table->unsignedInteger('requested_minutes');

            // Filled during approval
            $table->unsignedInteger('approved_minutes')->nullable();

            $table->text('reason');
            $table->string('document_path')->nullable();

            // Workflow
            $table->string('status', 30)
                ->default('pending')
                ->index();

            $table->foreignId('reviewed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();

            // Cancellation
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            // Request creator
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(
                ['employee_id', 'start_at', 'end_at'],
                'idx_overtime_employee_period'
            );

            $table->index(
                ['employee_id', 'status', 'start_at'],
                'idx_overtime_employee_status_start'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_requests');
    }
};
