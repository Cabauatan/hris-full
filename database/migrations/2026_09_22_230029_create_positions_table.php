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
        Schema::create('positions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('department_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            $table->index(
                ['department_id', 'is_active'],
                'idx_positions_department_active'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
