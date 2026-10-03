<?php

declare(strict_types=1);

namespace App\Http\Requests\Org;

use App\Enums\MemberStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Also guards plain authenticated reads of a single org (show).
 * Unknown org or non-member on SHOW → 404; non-member SWITCH → 403;
 * suspended → 403. (switch deliberately reveals membership absence to
 * someone who already knows the id — locked spec behavior.)
 */
class SwitchOrganizationRequest extends FormRequest
{
    private ?Organization $org = null;

    public function authorize(): bool
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
            if ($this->isMethodSafe()) {
                throw (new ModelNotFoundException)->setModel(Organization::class, (string) $this->route('organization'));
            }

            throw new HttpException(403, 'You are not a member of this organization.');
        }

        if ($membership->status !== MemberStatus::Active) {
            throw new HttpException(403, 'This action is unauthorized.');
        }

        $this->org = $org;

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function organization(): Organization
    {
        \assert($this->org instanceof Organization);

        return $this->org;
    }
}
