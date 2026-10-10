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
        Schema::create('recruitment_exams', function (Blueprint $table) {
            $table->id();
            $table->string('title', 180);
            $table->text('instructions')->nullable();
            $table->json('questions');
            $table->unsignedInteger('max_score');
            $table->decimal('passing_percentage', 5, 2)->default(60);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recruitment_exams');
    }
};
