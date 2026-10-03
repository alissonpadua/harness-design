<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Data\Profile\UpdateProfileData;
use App\Models\User;

final readonly class UpdateProfileAction
{
    public function handle(User $user, UpdateProfileData $data): void
    {
        $user->forceFill([
            'name' => $data->name,
            'locale' => $data->locale,
            'timezone' => $data->timezone,
        ])->save();
    }
}
