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
        Schema::create('daily_attendances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->date('attendance_date');

            $table->foreignId('work_schedule_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('shift_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->dateTime('scheduled_in')->nullable();
            $table->dateTime('scheduled_out')->nullable();

            $table->dateTime('actual_in')->nullable();
            $table->dateTime('actual_out')->nullable();

            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('undertime_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);

            $table->string('status', 50)->nullable();

            $table->boolean('is_rest_day')->default(false);
            $table->boolean('is_holiday')->default(false);

            $table->text('remarks')->nullable();

            $table->timestamp('calculated_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['employee_id', 'attendance_date'],
                'uq_daily_attendance_employee_date'
            );

            $table->index(
                ['attendance_date', 'status'],
                'idx_daily_attendance_date_status'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_attendances');
    }
};
