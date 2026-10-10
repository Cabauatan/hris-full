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
        Schema::create('job_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained()->restrictOnDelete();
            $table->string('desired_position', 180);
            $table->text('cover_letter')->nullable();
            $table->string('status', 30)->default('pending');
            $table->text('remarks')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('hired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('hired_at')->nullable();
            $table->date('start_date')->nullable();
            $table->timestamps();

            $table->index(['applicant_id', 'created_at', 'id'], 'idx_job_applications_applicant_created');
            $table->index(['status', 'created_at', 'id'], 'idx_job_applications_status_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_applications');
    }
};
