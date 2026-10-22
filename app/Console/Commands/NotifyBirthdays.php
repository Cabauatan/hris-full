<?php

namespace App\Console\Commands;

use App\Services\Notification\BirthdayNotificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hris:notify-birthdays')]
#[Description('Queue today\'s department and division coworker birthday notifications after 8 AM')]
class NotifyBirthdays extends Command
{
    public function handle(BirthdayNotificationService $notifications): int
    {
        $queued = $notifications->queueDue();
        $this->info('Queued birthday notifications for '.$queued.' employee(s).');

        return self::SUCCESS;
    }
}
