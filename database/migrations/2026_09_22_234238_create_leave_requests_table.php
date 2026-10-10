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
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('leave_type_id')
                ->constrained()
                ->restrictOnDelete();

            $table->date('start_date');
            $table->date('end_date');

            $table->string('day_portion', 20)->default('full_day');

            $table->decimal('requested_units', 8, 2);
            $table->decimal('approved_units', 8, 2)->nullable();

            $table->text('reason');
            $table->string('document_path')->nullable();

            $table->string('status', 30)
                ->default('pending')
                ->index();

            $table->foreignId('reviewed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(
                ['employee_id', 'start_date', 'end_date'],
                'idx_leave_requests_employee_period'
            );

            $table->index(
                ['employee_id', 'status', 'start_date'],
                'idx_leave_requests_employee_status_start'
            );

            $table->index(
                ['leave_type_id', 'status'],
                'idx_leave_requests_type_status'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
