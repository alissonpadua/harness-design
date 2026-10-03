<?php

declare(strict_types=1);

namespace App\Listeners\Org;

use App\Actions\Org\CreatePersonalWorkspaceAction;
use App\Events\Auth\UserRegistered;

final readonly class CreatePersonalWorkspaceOnRegistration
{
    public function __construct(private CreatePersonalWorkspaceAction $action) {}

    public function handle(UserRegistered $event): void
    {
        $this->action->handle($event->user);
    }
}
