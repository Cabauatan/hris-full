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
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('leave_type_id')
                ->constrained()
                ->restrictOnDelete();

            $table->unsignedSmallInteger('year');

            $table->decimal('balance', 10, 2)->default(0);

            $table->timestamps();

            $table->unique(
                ['employee_id', 'leave_type_id', 'year'],
                'uq_leave_balance_employee_type_year'
            );

            $table->index(
                ['year', 'leave_type_id'],
                'idx_leave_balances_year_type'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balances');
    }
};
