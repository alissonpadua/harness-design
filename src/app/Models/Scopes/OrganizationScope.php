<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Tenancy context default (AC-002.16): queries that do NOT already constrain
 * organization_id are fail-closed to the authenticated user's current
 * organization. Parent/relationship queries (which always carry an explicit
 * organization_id where) are respected as-written — org-addressed endpoints
 * stay correct when a member's current org differs. Console (seeders) exempt.
 */
/**
 * @implements Scope<Model>
 */
final class OrganizationScope implements Scope
{
    public function apply(Builder $query, Model $model): void
    {
        // Seeders/listeners run without any auth context; they must address all
        // rows explicitly (auth()->id() checks below would otherwise lock them out).
        if (app()->runningInConsole() && ! auth()->check()) {
            return;
        }

        foreach ($query->getQuery()->wheres as $where) {
            $column = (string) ($where['column'] ?? '');

            if ($column !== '' && preg_match('/(^|\.)organization_id$/', $column) === 1) {
                return;
            }
        }

        $organizationId = auth()->user()?->current_organization_id;

        $query->where($model->qualifyColumn('organization_id'), $organizationId ?? 0);
    }
}
