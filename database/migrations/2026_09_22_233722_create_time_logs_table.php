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
        Schema::create('time_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->dateTime('logged_at');

            $table->string('log_type', 20)->nullable();
            $table->string('source', 30)->default('manual');

            $table->string('device_code', 100)->nullable();
            $table->string('external_reference', 150)->nullable();

            $table->text('reason')->nullable();
            $table->text('remarks')->nullable();

            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->boolean('is_valid')->default(true);

            $table->timestamps();

            $table->index(
                ['employee_id', 'is_valid', 'logged_at'],
                'idx_time_logs_employee_valid_datetime'
            );

            $table->index(
                ['source', 'logged_at'],
                'idx_time_logs_source_datetime'
            );

            $table->index(
                'external_reference',
                'idx_time_logs_external_reference'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_logs');
    }
};
