<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Audit\AuditSecurityEvent;
use App\Models\User;
use App\Settings\RegistrationsSettings;

final readonly class SetRegistrationsOpenAction
{
    public function __construct(
        private RegistrationsSettings $settings,
        private AuditSecurityEvent $audit,
    ) {}

    public function handle(User $actor, bool $open): bool
    {
        $before = $this->settings->open;

        if ($before !== $open) {
            $this->settings->open = $open;
            $this->settings->save();

            // Drop the container-cached singleton so later resolutions in this
            // process (long-running workers / multi-request tests) see the new
            // value; php-fpm re-resolves per request anyway.
            app()->forgetInstance(RegistrationsSettings::class);

            $this->audit->log('settings_change', $actor, null, [
                'group' => 'registrations', 'from' => ['open' => $before], 'to' => ['open' => $open],
            ]);
        }

        return app(RegistrationsSettings::class)->open;
    }
}
