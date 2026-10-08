<?php

declare(strict_types=1);

namespace App\Actions\Org;

use App\Contracts\Org\OrgEntitlements;
use App\Exceptions\SubscriptionRequiredException;
use App\Models\BillingSubscription;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use Illuminate\Support\Facades\DB;

/**
 * Q1=A: org-facing windowed activity feed. Window comes from the EFFECTIVE
 * audit_retention_days entitlement (plan, then override). 0 => 402.
 */
final readonly class QueryOrgAuditAction
{
    public function __construct(private OrgEntitlements $entitlements) {}

    /**
     * @return array{rows: array<int, array<string, mixed>>, next_cursor: string|null, window_days: int}
     */
    public function handle(Organization $org, ?int $cursor, int $limit): array
    {
        $days = $this->entitlements->auditRetentionDays($org);

        if ($days <= 0) {
            throw new SubscriptionRequiredException;
        }

        $limit = min(100, max(1, $limit));
        $floor = now()->subDays($days)->toDateTimeString();

        $subjects = [
            [Organization::class, (string) $org->id],
            [OrganizationMembership::class, null],
            [BillingSubscription::class, null],
        ];

        $rows = DB::table('activity_log')
            ->where('created_at', '>=', $floor)
            ->when($cursor !== null, fn ($x) => $x->where('id', '<', $cursor))
            ->where(function ($x) use ($org, $subjects): void {
                $x->where(fn ($w) => $w->where('subject_type', $subjects[0][0])->where('subject_id', $subjects[0][1]));

                foreach ([$subjects[1][0], $subjects[2][0]] as $type) {
                    $sub = DB::table((new \ReflectionClass($type))->getName() === BillingSubscription::class ? 'subscriptions' : 'organization_user')
                        ->where('organization_id', $org->id)
                        ->pluck('id');

                    $x->orWhere(fn ($w) => $w->where('subject_type', $type)->whereIn('subject_id', $sub));
                }
            })
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $next = null;
        $items = $rows->all();

        if (count($items) > $limit) {
            array_pop($items);
            $last = $items[count($items) - 1] ?? null;
            $next = $last !== null ? (string) $last->id : null;
        }

        return [
            'rows' => array_map(static function (object $row): array {
                $r = (array) $row;

                return [
                    'id' => (int) $r['id'],
                    'event' => (string) $r['event'],
                    'subject_type' => $r['subject_type'],
                    'subject_id' => $r['subject_id'] !== null ? (int) $r['subject_id'] : null,
                    'causer_id' => $r['causer_id'] !== null ? (int) $r['causer_id'] : null,
                    'properties' => $r['properties'] !== null ? json_decode((string) $r['properties'], true) : null,
                    'created_at' => $r['created_at'],
                ];
            }, $items),
            'next_cursor' => $next,
            'window_days' => $days,
        ];
    }
}
