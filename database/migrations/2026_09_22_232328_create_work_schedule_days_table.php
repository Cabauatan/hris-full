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
        Schema::create('work_schedule_days', function (Blueprint $table) {
            $table->id();

            $table->foreignId('work_schedule_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedTinyInteger('day_of_week');

            $table->boolean('is_working_day')->default(true);

            $table->foreignId('shift_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(
                ['work_schedule_id', 'day_of_week'],
                'uq_work_schedule_day'
            );

            $table->index(
                ['shift_id', 'is_working_day'],
                'idx_work_schedule_days_shift_working'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedule_days');
    }
};
