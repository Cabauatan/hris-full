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
        Schema::create('departments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('division_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            $table->index(
                ['division_id', 'is_active'],
                'idx_departments_division_active'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
