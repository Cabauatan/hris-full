<?php

namespace App\Models;

use Database\Factories\TelegramDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TelegramDelivery extends Model
{
    /** @use HasFactory<TelegramDeliveryFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'resource_type', 'resource_id', 'event', 'event_id', 'recipient_type',
        'recipient_id', 'connection_key', 'source_version', 'occurred_at', 'status', 'sent_at',
    ];

    protected $hidden = ['connection_key'];

    protected function casts(): array
    {
        return [
            'resource_id' => 'integer', 'recipient_id' => 'integer',
            'occurred_at' => 'datetime', 'sent_at' => 'datetime',
        ];
    }
}
