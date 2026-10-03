<?php

declare(strict_types=1);

namespace App\Actions\Org;

use App\Auth\TwoFactorChallenge;
use App\Enums\MemberStatus;
use App\Enums\OrgRole;
use App\Events\Org\OwnershipTransferred;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class TransferOwnershipAction
{
    public function __construct(private TwoFactorChallenge $challenge) {}

    public function handle(User $actor, Organization $org, int $toUserId, string $password, ?string $otp): void
    {
        if ($org->isPersonal()) {
            throw new HttpException(403, 'Personal workspaces cannot be transferred.');
        }

        if (! Hash::check($password, $actor->password)) {
            throw ValidationException::withMessages(['current_password' => ['The provided password is incorrect.']]);
        }

        // 2FA re-check (AC-002.9): throws TwoFactorRequired/ValidationException like login.
        $this->challenge->verify($actor, $otp);

        $target = User::query()->find($toUserId);
        $targetMembership = $target === null ? null : $org->membershipFor($target);

        if ($targetMembership === null || $targetMembership->status !== MemberStatus::Active) {
            throw ValidationException::withMessages(['to_user_id' => ['The target user is not an active member.']]);
        }

        DB::transaction(function () use ($org, $actor, $target): void {
            DB::table('organization_user')
                ->where('organization_id', $org->id)
                ->where('user_id', $actor->id)
                ->update(['role' => OrgRole::Admin->value]);

            DB::table('organization_user')
                ->where('organization_id', $org->id)
                ->where('user_id', $target->id)
                ->update(['role' => OrgRole::Owner->value]);

            $org->forceFill(['owner_id' => $target->id])->save();
        });

        event(new OwnershipTransferred($org, $actor, $target));
    }
}
