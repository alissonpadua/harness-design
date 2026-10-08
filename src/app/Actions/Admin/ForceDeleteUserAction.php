<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Audit\AuditSecurityEvent;
use App\Enums\OrgType;
use App\Models\AuthLink;
use App\Models\NotificationPreference;
use App\Models\OauthAccount;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\ValidationException;

/**
 * Q3: TRUE hard delete. Owned teams must be transferred first; audit rows
 * survive by design (morph refs are id-based, no FK).
 */
final readonly class ForceDeleteUserAction
{
    public function __construct(private AuditSecurityEvent $audit) {}

    public function handle(User $actor, int $userId, string $confirmText): void
    {
        $target = User::withTrashed()->findOrFail($userId);

        if (! hash_equals(mb_strtolower($target->email), mb_strtolower($confirmText))) {
            throw ValidationException::withMessages(['confirm_text' => ['Confirmation text does not match the account email.']]);
        }

        // Personal workspaces belong to the account and go with it; any TEAM (even trashed —
        // the FK lives on) must be transferred/purged first (Q3, amended for personal floor).
        $teams = Organization::withTrashed()->where('owner_id', $target->id)->where('type', OrgType::Team)->count();

        if ($teams > 0) {
            throw ValidationException::withMessages(['user' => ["Transfer or delete owned organizations first ({$teams})."]]);
        }

        $this->audit->log('user_force_delete', $actor, $target, ['email' => $target->email]);

        foreach (Organization::withTrashed()->where('owner_id', $target->id)->where('type', OrgType::Personal)->get() as $personal) {
            $personal->memberships()->delete();
            $personal->forceDelete();
        }

        DatabaseNotification::query()->where('notifiable_type', User::class)->where('notifiable_id', $target->id)->delete();
        NotificationPreference::query()->where('user_id', $target->id)->delete();
        OauthAccount::query()->where('user_id', $target->id)->delete();
        $target->passkeys()->delete();
        AuthLink::query()->where('user_id', $target->id)->delete();
        $target->memberships()->delete();
        $target->tokens()->delete();
        $target->forceDelete();
    }
}
