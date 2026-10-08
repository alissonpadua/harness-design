<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use Illuminate\Support\Facades\DB;

final readonly class QueryAuditAction
{
    /**
     * @param  array{subject_type?: string|null, subject_id?: int|null, causer_id?: int|null, event?: string|null, from?: string|null, to?: string|null, cursor?: string|null, limit?: int}  $filters
     * @return array{rows: array<int, array<string, mixed>>, next_cursor: string|null}
     */
    public function handle(array $filters): array
    {
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 25)));

        $q = DB::table('activity_log');

        $q->when(! empty($filters['subject_type']), fn ($x) => $x->where('subject_type', 'like', '%'.(string) $filters['subject_type']));
        $q->when(! empty($filters['subject_id']), fn ($x) => $x->where('subject_id', (int) $filters['subject_id']));
        $q->when(! empty($filters['causer_id']), fn ($x) => $x->where('causer_id', (int) $filters['causer_id']));
        $q->when(! empty($filters['event']), fn ($x) => $x->where('event', (string) $filters['event']));
        $q->when(! empty($filters['from']), fn ($x) => $x->where('created_at', '>=', (string) $filters['from']));
        $q->when(! empty($filters['to']), fn ($x) => $x->where('created_at', '<=', (string) $filters['to']));
        $q->when(! empty($filters['cursor']), fn ($x) => $x->where('id', '<', (int) $filters['cursor']));

        $rows = $q->orderByDesc('id')->limit($limit + 1)->get()->all();

        $next = null;

        if (count($rows) > $limit) {
            array_pop($rows);
            $last = $rows[count($rows) - 1] ?? null;
            $next = $last !== null ? (string) $last->id : null;
        }

        return [
            'rows' => array_map(static function (object $row): array {
                $r = (array) $row;

                return [
                    'id' => (int) $r['id'],
                    'event' => (string) $r['event'],
                    'description' => (string) $r['description'],
                    'subject_type' => $r['subject_type'],
                    'subject_id' => $r['subject_id'] !== null ? (int) $r['subject_id'] : null,
                    'causer_type' => $r['causer_type'],
                    'causer_id' => $r['causer_id'] !== null ? (int) $r['causer_id'] : null,
                    'properties' => $r['properties'] !== null ? json_decode((string) $r['properties'], true) : null,
                    'attribute_changes' => $r['attribute_changes'] !== null ? json_decode((string) $r['attribute_changes'], true) : null,
                    'created_at' => $r['created_at'],
                ];
            }, $rows),
            'next_cursor' => $next,
        ];
    }
}
