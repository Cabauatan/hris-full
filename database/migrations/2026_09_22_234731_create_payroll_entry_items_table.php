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
        Schema::create('payroll_entry_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payroll_entry_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('code', 100);
            $table->string('name', 150);

            $table->string('type', 30);

            $table->decimal('quantity', 12, 4)->nullable();
            $table->decimal('rate', 15, 4)->nullable();

            $table->decimal('amount', 15, 2);

            // Optional trace back to originating HR transaction
            $table->string('source_type', 100)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->index(
                ['payroll_entry_id', 'type'],
                'idx_payroll_items_entry_type'
            );

            $table->index(
                ['source_type', 'source_id'],
                'idx_payroll_items_source'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_entry_items');
    }
};
