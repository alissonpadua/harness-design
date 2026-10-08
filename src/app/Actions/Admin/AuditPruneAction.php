<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use Illuminate\Support\Facades\DB;

/**
 * The ONLY deleter of audit rows (append-only contract, S6): hard cut at the
 * configured platform retention. Org-facing windows are a READ concern.
 */
final readonly class AuditPruneAction
{
    /**
     * @return array{deleted: int, candidates: int, before: string}
     */
    public function handle(bool $dryRun = false): array
    {
        $before = now()->subDays((int) config('audit.retention_days'))->toDateTimeString();

        $count = DB::table('activity_log')->where('created_at', '<', $before)->count();

        if (! $dryRun) {
            DB::table('activity_log')->where('created_at', '<', $before)->delete();
        }

        return ['deleted' => $dryRun ? 0 : $count, 'candidates' => $count, 'before' => $before];
    }
}
