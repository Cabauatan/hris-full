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
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();

            $table->string('code', 50)->unique();

            $table->date('period_start');
            $table->date('period_end');
            $table->date('pay_date');

            $table->string('pay_frequency', 30);

            $table->string('status', 30)
                ->default('open')
                ->index();

            $table->timestamps();

            $table->index(
                ['period_start', 'period_end'],
                'idx_payroll_periods_period'
            );

            $table->index(
                ['pay_date', 'status'],
                'idx_payroll_periods_pay_date_status'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_periods');
    }
};
