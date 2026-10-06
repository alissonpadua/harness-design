<?php

declare(strict_types=1);

namespace App\Notifications\Types;

use App\Notifications\Contracts\CatalogNotification;
use App\Notifications\Types\Concerns\BuildsCatalogMail;
use Illuminate\Mail\Mailable;

final class EmailVerification implements CatalogNotification
{
    use BuildsCatalogMail;

    public function type(): string
    {
        return 'auth.email_verification';
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
        return 'Verify your email address';
    }

    public function body(array $data): string
    {
        return 'Welcome! Confirm your email address to activate your account.';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public function lines(array $data): array
    {
        return ['Welcome! One quick step remains: confirm this email address to activate your account.'];
    }

    public function mailable(array $data): Mailable
    {
        return $this->mail($this->title($data), $this->lines($data), 'Verify email', (string) $data['url']);
    }
}
