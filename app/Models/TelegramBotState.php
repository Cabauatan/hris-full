<?php

namespace App\Models;

use Database\Factories\TelegramBotStateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TelegramBotState extends Model
{
    /** @use HasFactory<TelegramBotStateFactory> */
    use HasFactory;

    protected $primaryKey = 'bot_id';

    public $incrementing = false;

    protected $fillable = ['bot_id', 'next_update_id'];

    protected function casts(): array
    {
        return ['bot_id' => 'integer', 'next_update_id' => 'integer'];
    }
}
