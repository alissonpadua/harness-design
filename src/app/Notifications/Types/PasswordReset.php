<?php

declare(strict_types=1);

namespace App\Notifications\Types;

use App\Notifications\Contracts\CatalogNotification;
use App\Notifications\Types\Concerns\BuildsCatalogMail;
use Illuminate\Mail\Mailable;

final class PasswordReset implements CatalogNotification
{
    use BuildsCatalogMail;

    public function type(): string
    {
        return 'auth.password_reset';
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
        return 'Reset your password';
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
        return ['We received a request to reset your password.', 'Reset token: '.$data['token'], 'This token expires in '.config('auth.passwords.users.expire').' minutes and can be used once. If you did not request a reset, no further action is required.'];
    }

    public function mailable(array $data): Mailable
    {
        return $this->mail($this->title($data), $this->lines($data));
    }
}
