<?php

namespace App\Console\Commands;

use App\Exceptions\TelegramRequestException;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramConnectionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('telegram:commands {--check : Check the command menu without changing it} {--configured : Check configuration without contacting Telegram}')]
#[Description('Register or verify the HRIS Telegram command menu for private chats')]
class RegisterTelegramCommands extends Command
{
    public function handle(TelegramClient $client, TelegramConnectionService $connections): int
    {
        if (! $client->configured()) {
            $this->error('Telegram notifications are not configured.');

            return self::FAILURE;
        }
        if ($this->option('configured')) {
            $this->info('Telegram notifications are configured.');

            return self::SUCCESS;
        }
        try {
            $commands = $connections->commandMenu();
            if (! $this->option('check')) {
                $client->setCommands($commands);
            }
            if ($client->commands() !== $commands) {
                $this->error('Telegram command menu does not match the HRIS commands.');

                return self::FAILURE;
            }
            $this->info($this->option('check') ? 'Telegram command menu is up to date.' : 'Telegram command menu was registered and verified.');

            return self::SUCCESS;
        } catch (TelegramRequestException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Telegram command menu could not be verified.');

            return self::FAILURE;
        }
    }
}
