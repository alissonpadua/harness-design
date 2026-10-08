<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Org;

use App\Actions\Org\ClearOrgLogoAction;
use App\Actions\Org\SetOrgLogoAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Org\DeleteLogoRequest;
use App\Http\Requests\Org\UploadLogoRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OrgLogoController extends Controller
{
    public function __construct(
        private readonly SetOrgLogoAction $set,
        private readonly ClearOrgLogoAction $clear,
    ) {}

    public function update(UploadLogoRequest $form, int $organization): JsonResponse
    {
        /** @var User $actor */
        $actor = $form->user();
        $org = $form->organization();

        $this->set->handle($actor, $org, $form->file('logo'));

        return response()->json(['data' => ['logo_url' => $org->refresh()->logo_url]]);
    }

    public function destroy(DeleteLogoRequest $form, int $organization): Response
    {
        $this->clear->handle($form->organization());

        return response()->noContent();
    }

    public function show(string $identifier): SymfonyResponse
    {
        $org = Organization::query()->identifier($identifier)->first()
            ?? throw new NotFoundHttpException;

        $path = $org->logo_path;

        if (! is_string($path) || $path === '' || ! Storage::disk(config('filesystems.default'))->exists($path)) {
            throw new NotFoundHttpException;
        }

        if ((string) config('filesystems.disks.'.config('filesystems.default').'.driver') === 's3') {
            return redirect()->to(
                Storage::disk(config('filesystems.default'))->temporaryUrl($path, now()->addMinutes(5))
            );
        }

        return response((string) Storage::disk(config('filesystems.default'))->get($path), 200, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
