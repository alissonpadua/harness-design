<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Enums\OrgRole;
use App\Jobs\Notifications\DeliverNotification;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\CatalogDelivery;
use App\Notifications\NotificationCatalog;
use Illuminate\Notifications\AnonymousNotifiable;

/**
 * Single funnel for every notification in the app (arch rule: the only
 * place allowed to dispatch delivery jobs).
 */
final readonly class DispatchNotification
{
    public function __construct(private NotificationCatalog $catalog) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>|null  $channels
     */
    public function user(User $user, string $type, array $data = [], ?array $channels = null): void
    {
        $this->catalog->get($type);

        dispatch(new DeliverNotification($user, new CatalogDelivery($type, $data, $channels)));
    }

    /**
     * Mail-only lane for unregistered recipients (invites, old address).
     *
     * @param  array<string, mixed>  $data
     */
    public function email(string $address, string $type, array $data = []): void
    {
        $this->catalog->get($type);

        dispatch(new DeliverNotification(
            (new AnonymousNotifiable)->route('mail', $address),
            new CatalogDelivery($type, $data, ['mail']),
        ));
    }

    /**
     * Org-scoped type: owner + org admins (+ explicitly added users).
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $extraUserIds
     */
    public function org(Organization $org, string $type, array $data = [], array $extraUserIds = []): void
    {
        $recipients = User::query()
            ->whereKey(
                $org->members()
                    ->whereIn('organization_user.role', [OrgRole::Owner->value, OrgRole::Admin->value])
                    ->pluck('users.id')
                    ->merge($extraUserIds)
                    ->push($org->owner_id)
                    ->unique()
            )
            ->get();

        foreach ($recipients as $recipient) {
            $this->user($recipient, $type, $data);
        }
    }
}
