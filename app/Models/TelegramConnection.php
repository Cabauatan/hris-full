<?php

namespace App\Models;

use Database\Factories\TelegramConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TelegramConnection extends Model
{
    /** @use HasFactory<TelegramConnectionFactory> */
    use HasFactory;

    protected $fillable = [
        'recipient_type', 'recipient_id', 'bot_id', 'chat_id', 'connection_key', 'link_token_hash',
        'link_token_expires_at', 'linked_at', 'unsubscribed_at',
    ];

    protected $hidden = ['chat_id', 'connection_key', 'link_token_hash', 'link_token_expires_at'];

    protected function casts(): array
    {
        return [
            'recipient_id' => 'integer',
            'bot_id' => 'integer',
            'link_token_expires_at' => 'datetime',
            'linked_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }
}
