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
        Schema::create('employee_compensations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->decimal('basic_salary', 15, 2);

            $table->string('pay_frequency', 30);
            $table->string('currency', 3)->default('PHP');

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
                'idx_compensations_employee_effective'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_compensations');
    }
};
