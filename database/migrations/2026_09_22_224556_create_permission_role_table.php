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
        Schema::create('permission_role', function (Blueprint $table) {
            $table->id();

            $table->foreignId('permission_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('role_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(
                ['permission_id', 'role_id'],
                'uq_permission_role'
            );

            $table->index(
                ['role_id', 'permission_id'],
                'idx_permission_role_role_permission'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_role');
    }
};
