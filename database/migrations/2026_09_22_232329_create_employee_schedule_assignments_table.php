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
        Schema::create('employee_schedule_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('work_schedule_id')
                ->constrained()
                ->restrictOnDelete();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->text('remarks')->nullable();

            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(
                ['employee_id', 'effective_from', 'effective_to'],
                'idx_employee_schedule_effective'
            );

            $table->index(
                ['work_schedule_id', 'effective_from'],
                'idx_employee_schedule_work_schedule'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_schedule_assignments');
    }
};
