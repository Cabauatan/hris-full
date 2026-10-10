<?php

namespace App\Exceptions;

use RuntimeException;

class TelegramRequestException extends RuntimeException
{
    public function __construct(public readonly string $category, public readonly int $errorCode = 0, public readonly ?int $retryAfter = null)
    {
        parent::__construct('Telegram request failed ('.$category.').');
    }
}
