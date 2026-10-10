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
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();

            $table->string('title', 200);
            $table->text('content');

            // normal, important, urgent
            $table->string('priority', 30)->default('normal');

            // Publication window
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // Dashboard control
            $table->boolean('show_on_dashboard')->default(true);

            // Optional attachment stored privately
            $table->string('attachment_path')->nullable();

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(
                ['is_active', 'show_on_dashboard', 'published_at'],
                'idx_announcements_dashboard'
            );

            $table->index(
                ['expires_at'],
                'idx_announcements_expires'
            );

            $table->index(
                ['priority', 'published_at'],
                'idx_announcements_priority_published'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
