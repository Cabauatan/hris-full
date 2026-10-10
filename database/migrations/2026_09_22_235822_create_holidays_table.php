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
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();

            $table->string('name', 150);
            $table->date('holiday_date');

            // regular, special_non_working, special_working, company
            $table->string('holiday_type', 50);

            $table->text('description')->nullable();

            // Useful for attendance/payroll interpretation
            $table->boolean('is_working_day')->default(false);
            $table->boolean('is_paid')->default(true);

            $table->boolean('is_active')->default(true)->index();

            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(
                ['holiday_date', 'name'],
                'uq_holidays_date_name'
            );

            $table->index(
                ['holiday_date', 'is_active'],
                'idx_holidays_date_active'
            );

            $table->index(
                ['holiday_type', 'holiday_date'],
                'idx_holidays_type_date'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
