<?php

declare(strict_types=1);

namespace App\Notifications\Types;

use App\Notifications\Contracts\CatalogNotification;
use App\Notifications\Types\Concerns\BuildsCatalogMail;
use Illuminate\Mail\Mailable;

final class PlanChanged implements CatalogNotification
{
    use BuildsCatalogMail;

    public function type(): string
    {
        return 'billing.plan_changed';
    }

    public function locked(): bool
    {
        return false;
    }

    public function emailDefault(): bool
    {
        return true;
    }

    public function title(array $data): string
    {
        return 'Plan updated for '.$data['org_name'];
    }

    public function body(array $data): string
    {
        return implode(' ', $this->lines($data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public function lines(array $data): array
    {
        return ['The plan for '.$data['org_name'].' is now '.$data['to'].'.', 'New limits are live immediately in the billing portal.'];
    }

    public function mailable(array $data): Mailable
    {
        return $this->mail($this->title($data), $this->lines($data), 'Open billing', isset($data['portal_url']) ? (string) $data['portal_url'] : null);
    }
}
