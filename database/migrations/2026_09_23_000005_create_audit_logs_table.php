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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // User who performed the action
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // create, update, delete, approve, reject, login, etc.
            $table->string('action', 50);

            // Generic affected entity
            $table->string('auditable_type', 150)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();

            // Before / after snapshots
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            // Request/context information
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->string('request_method', 10)->nullable();
            $table->text('request_url')->nullable();

            // Optional correlation identifier
            $table->uuid('request_id')->nullable();

            $table->text('remarks')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(
                ['auditable_type', 'auditable_id', 'created_at'],
                'idx_audit_logs_auditable'
            );

            $table->index(
                ['user_id', 'created_at'],
                'idx_audit_logs_user_created'
            );

            $table->index(
                ['action', 'created_at'],
                'idx_audit_logs_action_created'
            );

            $table->index(
                'request_id',
                'idx_audit_logs_request_id'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
