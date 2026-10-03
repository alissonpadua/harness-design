<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

use App\Auth\OrgAuthorizer;
use App\Enums\MemberStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Resolves {organization} by id-or-slug, then:
 *  - not found / not a member  → 404 (no existence leak, AC-002.16)
 *  - suspended member          → 403 (AC-002.8)
 *  - missing $permission       → 403 'This action is unauthorized.' (AC-001.23 parity)
 */
abstract class OrgScopedRequest extends FormRequest
{
    protected string $permission = 'org.view';

    private ?Organization $org = null;

    public function authorize(OrgAuthorizer $authorizer): bool
    {
        /** @var ?Organization $org */
        $org = Organization::query()->identifier((string) $this->route('organization'))->first();

        if ($org === null) {
            throw (new ModelNotFoundException)->setModel(Organization::class);
        }

        /** @var User $user */
        $user = $this->user();

        $membership = $org->membershipFor($user);

        if ($membership === null) {
            throw (new ModelNotFoundException)->setModel(Organization::class, (string) $this->route('organization'));
        }

        if ($membership->status !== MemberStatus::Active) {
            throw new HttpException(403, 'This action is unauthorized.');
        }

        if ($this->permission !== '' && ! $authorizer->can($user, $org, $this->permission)) {
            throw new HttpException(403, 'This action is unauthorized.');
        }

        $this->org = $org;

        return true;
    }

    public function organization(): Organization
    {
        \assert($this->org instanceof Organization); // authorize() resolved it first

        return $this->org;
    }
}
