<?php

declare(strict_types=1);

namespace App\Notifications\Types;

use App\Notifications\Contracts\CatalogNotification;
use App\Notifications\Types\Concerns\BuildsCatalogMail;
use Illuminate\Mail\Mailable;

/**
 * Two mail variants under one locked type: to_old (security notice) and
 * to_new (confirmation). In-app rows mirror the variant inside data.
 */
final class EmailChange implements CatalogNotification
{
    use BuildsCatalogMail;

    public const OLD = 'to_old';

    public const NEW = 'to_new';

    public function type(): string
    {
        return 'auth.email_change';
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
        return $this->variant($data) === self::OLD
            ? 'Your email address was changed'
            : 'Confirm your new email address';
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
        return $this->variant($data) === self::OLD
            ? ['The email address on your account was changed from '.($data['from'] ?? 'the previous address').' to '.($data['to'] ?? 'a new address').'.', 'If you did not make this change, secure your account immediately.']
            : ['Confirm '.($data['to'] ?? '').' as your new address to complete the change.'];
    }

    public function mailable(array $data): Mailable
    {
        if ($this->variant($data) === self::OLD) {
            return $this->mail(
                $this->title($data),
                $this->lines($data),
                'Secure my account',
                isset($data['security_url']) ? (string) $data['security_url'] : null,
            );
        }

        return $this->mail(
            $this->title($data),
            $this->lines($data),
            'Confirm new email',
            isset($data['url']) ? (string) $data['url'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function variant(array $data): string
    {
        return (string) ($data['variant'] ?? self::NEW);
    }
}
