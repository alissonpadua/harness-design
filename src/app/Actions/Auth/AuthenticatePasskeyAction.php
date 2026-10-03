<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\LoginTokenData;
use App\Enums\DeviceType;
use App\Exceptions\EmailNotVerifiedException;
use App\Exceptions\LoginFailedException;
use App\Models\User;
use LaravelWebauthn\Models\WebauthnKey;
use LaravelWebauthn\Services\Webauthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Throwable;

/**
 * Passkey assertion = phishing-resistant proof → satisfies mandatory-2FA
 * policies without a TOTP step; deliberately skips TwoFactorChallenge.
 */
final readonly class AuthenticatePasskeyAction
{
    public function __construct(private IssueDeviceTokenAction $issueToken) {}

    /**
     * @param  array<string, mixed>  $credential
     */
    public function handle(array $credential, DeviceType $deviceType, string $ip, ?string $userAgent): LoginTokenData
    {
        // Mirror the package's own lookup (rawId is base64url; column may hold padded or unpadded encoding).
        $rawId = (string) $credential['rawId'];
        $rawBytes = base64_decode(strtr($rawId, '-_', '+/'), true) ?: '';

        /** @var WebauthnKey|null $key */
        $key = Webauthn::model()::query()
            ->where(fn ($q) => $q->where('credentialId', Base64UrlSafe::encode($rawBytes))
                ->orWhere('credentialId', Base64UrlSafe::encodeUnpadded($rawBytes)))
            ->first();

        if ($key === null) {
            throw new LoginFailedException;
        }

        /** @var User|null $user */
        $user = User::query()->find($key->getAttribute('user_id'));

        if ($user === null) {
            throw new LoginFailedException;
        }

        if (! $user->hasVerifiedEmail()) {
            throw new EmailNotVerifiedException;
        }

        try {
            Webauthn::validateAssertion($user, $credential);
        } catch (Throwable $e) {
            file_put_contents(storage_path('passkey-debug.log'), 'ASSERT: '.$e::class.': '.$e->getMessage().'
', FILE_APPEND);

            throw new LoginFailedException;
        }

        return $this->issueToken->handle($user, $deviceType, $ip, $userAgent);
    }
}
