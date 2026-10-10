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
        Schema::create('telegram_connections', function (Blueprint $table) {
            $table->id();
            $table->string('recipient_type', 20);
            $table->unsignedBigInteger('recipient_id');
            $table->unsignedBigInteger('bot_id')->nullable();
            $table->string('chat_id', 30)->nullable()->index();
            $table->uuid('connection_key')->unique();
            $table->char('link_token_hash', 64)->nullable()->unique();
            $table->timestamp('link_token_expires_at')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
            $table->unique(['recipient_type', 'recipient_id'], 'uq_telegram_connections_recipient');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_connections');
    }
};
