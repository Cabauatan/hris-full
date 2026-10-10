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
        Schema::create('job_openings', function (Blueprint $table) {
            $table->id();
            $table->string('title', 180);
            $table->text('description');
            $table->text('requirements')->nullable();
            $table->string('location', 180)->nullable();
            $table->string('employment_type', 50)->nullable();
            $table->unsignedSmallInteger('vacancies')->default(1);
            $table->string('status', 20)->default('open');
            $table->date('closes_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'closes_on', 'id'], 'idx_job_openings_status_closes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_openings');
    }
};
