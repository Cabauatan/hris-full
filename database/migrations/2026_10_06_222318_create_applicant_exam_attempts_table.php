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
        Schema::create('applicant_exam_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_application_id')->constrained()->restrictOnDelete();
            $table->foreignId('recruitment_exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('questions');
            $table->json('answers')->nullable();
            $table->json('grades')->nullable();
            $table->string('status', 30)->default('assigned');
            $table->decimal('automatic_score', 8, 2)->default(0);
            $table->decimal('score', 8, 2)->nullable();
            $table->unsignedInteger('max_score');
            $table->decimal('passing_percentage', 5, 2);
            $table->decimal('percentage', 5, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['job_application_id', 'recruitment_exam_id'], 'uq_applicant_exam_application_exam');
            $table->index(['status', 'submitted_at', 'id'], 'idx_applicant_exam_status_submitted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('applicant_exam_attempts');
    }
};
