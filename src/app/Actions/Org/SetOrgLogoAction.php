<?php

declare(strict_types=1);

namespace App\Actions\Org;

use App\Models\Organization;
use App\Models\User;
use App\Support\ImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Sole upload surface in the product (spec 006 Q4=B): authoritative finfo
 * sniff + GD re-encode to WEBP, stored PRIVATE. Public reads only ever go
 * through the dedicated logo route.
 */
final readonly class SetOrgLogoAction
{
    private const MIME_MAP = ['image/png' => true, 'image/jpeg' => true, 'image/webp' => true];

    public function __construct(private ImageProcessor $images) {}

    public function handle(User $actor, Organization $org, UploadedFile $file): void
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($file->getRealPath());

        if (! isset(self::MIME_MAP[$mime])) {
            throw ValidationException::withMessages(['logo' => ['The uploaded file is not a supported image.']]);
        }

        $webp = $this->images->toNormalizedWebp((string) file_get_contents($file->getRealPath()));

        $path = 'orgs/'.$org->id.'/logo.webp';
        Storage::disk(config('filesystems.default'))->put($path, $webp);

        $org->forceFill(['logo_path' => $path, 'logo_hash' => hash('sha256', $webp)])->save();
    }
}
