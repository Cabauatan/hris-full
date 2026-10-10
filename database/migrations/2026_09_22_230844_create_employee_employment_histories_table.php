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
        Schema::create('employee_employment_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('position_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('supervisor_employee_id')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete();

            $table->string('employment_type', 50);
            $table->string('employment_status', 50);

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->string('change_type', 50);
            $table->text('reason')->nullable();
            $table->text('remarks')->nullable();

            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(
                ['employee_id', 'effective_from', 'effective_to'],
                'idx_employment_history_employee_effective'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_employment_histories');
    }
};
