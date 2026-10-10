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
        Schema::create('approval_delegations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('delegator_user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('delegate_user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            $table->text('reason')->nullable();

            $table->boolean('is_active')
                ->default(true)
                ->index();

            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(
                ['delegator_user_id', 'is_active', 'starts_at', 'ends_at'],
                'idx_approval_delegations_delegator_period'
            );

            $table->index(
                ['delegate_user_id', 'is_active', 'starts_at', 'ends_at'],
                'idx_approval_delegations_delegate_period'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_delegations');
    }
};
