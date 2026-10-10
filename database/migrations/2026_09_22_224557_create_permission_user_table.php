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
        Schema::create('permission_user', function (Blueprint $table) {
            $table->id();

            $table->foreignId('permission_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(
                ['permission_id', 'user_id'],
                'uq_permission_user'
            );

            $table->index(
                ['user_id', 'permission_id'],
                'idx_permission_user_user_permission'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_user');
    }
};
