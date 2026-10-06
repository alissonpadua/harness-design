<?php

declare(strict_types=1);

namespace App\Notifications\Types;

use App\Notifications\Contracts\CatalogNotification;
use App\Notifications\Types\Concerns\BuildsCatalogMail;
use Illuminate\Mail\Mailable;

final class NewDeviceLogin implements CatalogNotification
{
    use BuildsCatalogMail;

    public function type(): string
    {
        return 'auth.new_device_login';
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
        return 'New sign-in to your account';
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
        return ['Your account was used to sign in from a new device.', 'Device: '.($data['agent'] ?? 'unknown'), 'IP: '.$data['ip'], 'Time: '.$data['time'], 'If this was not you, change your password immediately.'];
    }

    public function mailable(array $data): Mailable
    {
        return $this->mail($this->title($data), $this->lines($data), 'Secure my account', isset($data['security_url']) ? (string) $data['security_url'] : null);
    }
}
