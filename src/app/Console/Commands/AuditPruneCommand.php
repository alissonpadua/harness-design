<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Admin\AuditPruneAction;
use Illuminate\Console\Command;

final class AuditPruneCommand extends Command
{
    protected $signature = 'audit:prune {--dry-run : report what would be deleted without deleting}';

    protected $description = 'Delete audit rows older than AUDIT_RETENTION_DAYS (append-only retention prune, the only deleter)';

    public function handle(AuditPruneAction $prune): int
    {
        $result = $prune->handle(dryRun: (bool) $this->option('dry-run'));

        $this->info(sprintf(
            '%s %d row(s) older than %s',
            $this->option('dry-run') ? 'would delete' : 'deleted',
            (int) $result['candidates'],
            $result['before'],
        ));

        return self::SUCCESS;
    }
}
