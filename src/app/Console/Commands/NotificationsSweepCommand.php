<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Notifications\SweepFailed;
use Illuminate\Console\Command;

final class NotificationsSweepCommand extends Command
{
    protected $signature = 'notifications:sweep-failed';

    protected $description = 'Retry eligible failed notifications (scheduled every 15 minutes)';

    public function handle(SweepFailed $sweep): int
    {
        $stats = $sweep->handle();
        $this->info(sprintf('processed=%d succeeded=%d failed=%d', $stats['processed'], $stats['succeeded'], $stats['failed']));

        return self::SUCCESS;
    }
}
