<?php

declare(strict_types=1);

namespace App\Notifications\Types;

use App\Notifications\Contracts\CatalogNotification;
use App\Notifications\Types\Concerns\BuildsCatalogMail;
use Illuminate\Mail\Mailable;

final class TrialEndingSoon implements CatalogNotification
{
    use BuildsCatalogMail;

    public function type(): string
    {
        return 'billing.trial_ending_soon';
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
        return 'Your '.$data['plan_name'].' trial ends soon';
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
        return [$data['org_name'].chr(39).'s trial ends in '.$data['days_left'].' day(s).'];
    }

    public function mailable(array $data): Mailable
    {
        return $this->mail($this->title($data), $this->lines($data), 'Start billing', isset($data['portal_url']) ? (string) $data['portal_url'] : null);
    }
}
