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
        Schema::create('role_user', function (Blueprint $table) {
            $table->id();

            $table->foreignId('role_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(
                ['role_id', 'user_id'],
                'uq_role_user'
            );

            $table->index(
                ['user_id', 'role_id'],
                'idx_role_user_user_role'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
    }
};
