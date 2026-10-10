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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->nullable()
                ->unique()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('position_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('supervisor_employee_id')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete();

            $table->string('employee_number', 50)->unique();

            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('suffix', 20)->nullable();

            $table->date('birth_date')->nullable();
            $table->string('gender', 30)->nullable();
            $table->string('civil_status', 30)->nullable();

            $table->string('personal_email')->nullable();
            $table->string('company_email')->nullable()->unique();
            $table->string('mobile_number', 50)->nullable();

            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 100)->default('Philippines');

            $table->date('hire_date');
            $table->date('regularization_date')->nullable();
            $table->date('separation_date')->nullable();

            $table->string('employment_type', 50);
            $table->string('employment_status', 50)
                ->default('active')
                ->index();

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            $table->index(
                ['position_id', 'is_active'],
                'idx_employees_position_active'
            );

            $table->index(
                ['employment_status', 'is_active'],
                'idx_employees_status_active'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
