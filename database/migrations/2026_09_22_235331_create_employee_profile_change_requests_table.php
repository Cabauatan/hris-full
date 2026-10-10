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
        Schema::create('employee_profile_change_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->text('reason')->nullable();

            // Optional supporting document stored privately
            $table->string('document_path')->nullable();

            $table->string('status', 30)
                ->default('pending')
                ->index();

            $table->foreignId('reviewed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();

            // Set only after approved changes are actually applied
            $table->timestamp('applied_at')->nullable();

            $table->foreignId('applied_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(
                ['employee_id', 'status', 'created_at'],
                'idx_profile_changes_employee_status_created'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_profile_change_requests');
    }
};
