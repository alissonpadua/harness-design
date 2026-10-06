<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Notifications\SweepFailed;
use Illuminate\Console\Command;

final class NotificationsRetryFailedCommand extends Command
{
    protected $signature = 'notifications:retry-failed {--id= : specific failed row} {--all : include parked rows}';

    protected $description = 'Manually redeliver failed notifications';

    public function handle(SweepFailed $sweep): int
    {
        $id = $this->option('id');
        $stats = $sweep->handle(manualAll: (bool) $this->option('all') || $id !== null, onlyId: $id !== null ? (int) $id : null);

        $this->info(sprintf('processed=%d succeeded=%d failed=%d', $stats['processed'], $stats['succeeded'], $stats['failed']));

        return $stats['processed'] === 0 ? self::SUCCESS : ($stats['failed'] > 0 ? self::FAILURE : self::SUCCESS);
    }
}
