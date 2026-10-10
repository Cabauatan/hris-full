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
        Schema::create('approval_actions', function (Blueprint $table) {
            $table->id();

            // Generic request reference
            $table->string('approvable_type', 100);
            $table->unsignedBigInteger('approvable_id');

            // Who performed the action
            $table->foreignId('acted_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // approve, reject, cancel, etc.
            $table->string('action', 30);

            $table->text('remarks')->nullable();

            $table->timestamp('acted_at');

            $table->timestamps();

            $table->index(
                ['approvable_type', 'approvable_id', 'acted_at'],
                'idx_approval_actions_approvable'
            );

            $table->index(
                ['acted_by_user_id', 'acted_at'],
                'idx_approval_actions_actor'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_actions');
    }
};
