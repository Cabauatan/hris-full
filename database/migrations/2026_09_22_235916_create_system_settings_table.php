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
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();

            $table->string('group', 100)->default('general');

            $table->string('key', 150)->unique();

            $table->text('value')->nullable();

            // string, integer, decimal, boolean, json
            $table->string('type', 30)->default('string');

            $table->text('description')->nullable();

            // Safe to expose to authenticated frontend configuration
            $table->boolean('is_public')->default(false);

            // Prevent normal UI editing of protected/system-managed settings
            $table->boolean('is_editable')->default(true);

            $table->foreignId('updated_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(
                ['group', 'is_public'],
                'idx_system_settings_group_public'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
