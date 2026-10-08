<?php

declare(strict_types=1);

namespace App\Actions\Org;

use App\Models\Organization;
use Illuminate\Support\Facades\Storage;

final readonly class ClearOrgLogoAction
{
    public function handle(Organization $org): void
    {
        $path = $org->logo_path;

        if (is_string($path) && $path !== '') {
            Storage::disk(config('filesystems.default'))->delete($path);
        }

        $org->forceFill(['logo_path' => null, 'logo_hash' => null])->save();
    }
}
