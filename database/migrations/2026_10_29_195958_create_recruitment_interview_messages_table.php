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
        Schema::create('recruitment_interview_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruitment_interview_id')->constrained()->cascadeOnDelete();
            $table->string('sender_kind', 20);
            $table->unsignedBigInteger('sender_id');
            $table->string('sender_name', 255);
            $table->uuid('client_message_id');
            $table->text('body');
            $table->timestamps();

            $table->unique(['recruitment_interview_id', 'sender_kind', 'sender_id', 'client_message_id'], 'uq_interview_messages_sender_client');
            $table->index(['recruitment_interview_id', 'id'], 'idx_interview_messages_room_cursor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recruitment_interview_messages');
    }
};
