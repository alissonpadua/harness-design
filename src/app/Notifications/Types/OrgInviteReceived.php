<?php

declare(strict_types=1);

namespace App\Notifications\Types;

use App\Notifications\Contracts\CatalogNotification;
use App\Notifications\Types\Concerns\BuildsCatalogMail;
use Illuminate\Mail\Mailable;

final class OrgInviteReceived implements CatalogNotification
{
    use BuildsCatalogMail;

    public function type(): string
    {
        return 'org.invite_received';
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
        return 'You were invited to '.$data['org_name'];
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
        return ['You have been invited to join '.$data['org_name'].' as '.$data['role'].'.', 'Accept the invitation to get started.'];
    }

    public function mailable(array $data): Mailable
    {
        return $this->mail($this->title($data), $this->lines($data), 'Accept invitation', (string) $data['url']);
    }
}
