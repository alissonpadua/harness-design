<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class ListUsersAction
{
    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function handle(?string $q, string $status, int $perPage): LengthAwarePaginator
    {
        return User::query()
            ->when($q !== null && $q !== '', fn ($w) => $w->where(
                fn ($x) => $x->whereRaw('lower(name) like ?', ['%'.mb_strtolower((string) $q).'%'])
                    ->orWhereRaw('lower(email) like ?', ['%'.mb_strtolower((string) $q).'%'])
            ))
            ->when($status === 'suspended', fn ($w) => $w->whereNotNull('suspended_at'))
            ->when($status === 'deleted', fn ($w) => $w->onlyTrashed())
            ->when($status === 'active', fn ($w) => $w->whereNull('suspended_at'))
            ->withCount('memberships')
            ->latest('id')
            ->paginate(min(100, max(1, $perPage)));
    }
}
