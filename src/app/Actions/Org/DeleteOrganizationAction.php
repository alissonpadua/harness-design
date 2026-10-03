<?php

declare(strict_types=1);

namespace App\Actions\Org;

use App\Events\Org\OrgDeleted;
use App\Models\Organization;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class DeleteOrganizationAction
{
    public function handle(Organization $org, string $confirmText): void
    {
        if ($org->isPersonal()) {
            throw new HttpException(403, 'Personal workspaces cannot be deleted.');
        }

        if (! hash_equals($org->name, $confirmText)) {
            throw ValidationException::withMessages([
                'confirm_text' => ['Type the organization name exactly to confirm deletion.'],
            ]);
        }

        // release current-org pointers before soft delete (AC-002.6)
        Organization::resetCurrentPointer($org);

        $org->delete();

        event(new OrgDeleted($org));
    }
}
