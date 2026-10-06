<?php

declare(strict_types=1);

namespace App\Notifications\Types;

use App\Notifications\Contracts\CatalogNotification;
use App\Notifications\Types\Concerns\BuildsCatalogMail;
use Illuminate\Mail\Mailable;

final class MagicLink implements CatalogNotification
{
    use BuildsCatalogMail;

    public function type(): string
    {
        return 'auth.magic_link';
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
        return 'Your sign-in link';
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
        return ['Use the token below to sign in — paste it into the app or send it to /api/v1/auth/magic-link/consume.', 'Sign-in token: '.$data['token'], 'This link expires in '.$data['minutes'].' minutes and can be used once. It also verifies your email address.'];
    }

    public function mailable(array $data): Mailable
    {
        return $this->mail($this->title($data), $this->lines($data));
    }
}
