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
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payroll_entry_id')
                ->unique()
                ->constrained('payroll_entries')
                ->restrictOnDelete();

            $table->foreignId('employee_id')
                ->constrained()
                ->restrictOnDelete();

            // Generated payslip document in private storage
            $table->string('file_path')->nullable();

            $table->timestamp('generated_at')->nullable();

            $table->foreignId('generated_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Employee visibility
            $table->timestamp('published_at')->nullable();

            $table->foreignId('published_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // First time employee viewed/opened the payslip
            $table->timestamp('viewed_at')->nullable();

            $table->timestamps();

            $table->index(
                ['employee_id', 'published_at'],
                'idx_payslips_employee_published'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
    }
};
