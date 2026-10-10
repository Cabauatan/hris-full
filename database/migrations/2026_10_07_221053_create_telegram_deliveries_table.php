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
        Schema::create('telegram_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('resource_type', 60);
            $table->unsignedBigInteger('resource_id');
            $table->string('event', 40);
            $table->string('event_id', 180);
            $table->string('recipient_type', 20);
            $table->unsignedBigInteger('recipient_id');
            $table->uuid('connection_key');
            $table->string('source_version', 120)->nullable();
            $table->timestamp('occurred_at');
            $table->string('status', 20)->default('queued');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at'], 'idx_telegram_deliveries_status_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_deliveries');
    }
};
