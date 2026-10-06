<?php

declare(strict_types=1);

namespace App\Notifications\Types;

use App\Notifications\Contracts\CatalogNotification;
use App\Notifications\Types\Concerns\BuildsCatalogMail;
use Illuminate\Mail\Mailable;

final class OwnershipTransferred implements CatalogNotification
{
    use BuildsCatalogMail;

    public function type(): string
    {
        return 'org.ownership_transferred';
    }

    public function locked(): bool
    {
        return true;
    }

    public function emailDefault(): bool
    {
        return true;
    }

    public function title(array $data): string
    {
        return 'Team ownership transferred';
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
        return ['Ownership of '.$data['org_name'].' was transferred from '.$data['from_name'].' to '.$data['to_name'].'.', 'You are receiving this notice for the security records of your team.'];
    }

    public function mailable(array $data): Mailable
    {
        return $this->mail($this->title($data), $this->lines($data));
    }
}
