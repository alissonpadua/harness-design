<?php

declare(strict_types=1);

namespace App\Actions\Org;

use App\Enums\OrgRole;
use App\Enums\OrgType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class LeaveOrganizationAction
{
    public function handle(User $user, Organization $org): void
    {
        if ($org->isPersonal()) {
            throw new HttpException(403, 'Personal workspaces cannot be left.');
        }

        if ($org->membershipFor($user)?->role === OrgRole::Owner) {
            throw new HttpException(403, 'Owners must transfer ownership before leaving.');
        }

        DB::table('organization_user')
            ->where('organization_id', $org->id)
            ->where('user_id', $user->id)
            ->delete();

        if ($user->current_organization_id === $org->id) {
            $personal = $this->personalWorkspace($user);
            $user->forceFill(['current_organization_id' => $personal?->id])->save();
        }
    }

    private function personalWorkspace(User $user): ?Organization
    {
        return Organization::query()
            ->where('owner_id', $user->id)
            ->where('type', OrgType::Personal)
            ->first();
    }
}
