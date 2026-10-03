<?php

declare(strict_types=1);

namespace App\Actions\Org;

use App\Models\Organization;

final readonly class UpdateOrganizationAction
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public function handle(Organization $org, array $settings): void
    {
        $org->forceFill($settings)->save();
    }
}
