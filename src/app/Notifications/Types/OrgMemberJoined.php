<?php

declare(strict_types=1);

namespace App\Notifications\Types;

use App\Notifications\Contracts\CatalogNotification;
use App\Notifications\Types\Concerns\BuildsCatalogMail;
use Illuminate\Mail\Mailable;

final class OrgMemberJoined implements CatalogNotification
{
    use BuildsCatalogMail;

    public function type(): string
    {
        return 'org.member_joined';
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
        return 'New member in '.$data['org_name'];
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
        return [$data['member_name'].' joined '.$data['org_name'].' as '.$data['role'].'.'];
    }

    public function mailable(array $data): Mailable
    {
        return $this->mail($this->title($data), $this->lines($data));
    }
}
