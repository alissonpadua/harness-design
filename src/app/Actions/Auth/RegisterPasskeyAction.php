<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use LaravelWebauthn\Models\WebauthnKey;
use LaravelWebauthn\Services\Webauthn;
use Throwable;

final readonly class RegisterPasskeyAction
{
    public function __construct(private CreatePasskeyOptionsAction $options) {}

    /**
     * @param  array<string, mixed>  $credential
     */
    public function handle(User $user, string $name, array $credential): WebauthnKey
    {
        $this->options->guardMax($user);

        try {
            /** @var WebauthnKey $key */
            $key = Webauthn::validateAttestation($user, $credential, $name);
        } catch (Throwable $e) {
            file_put_contents(storage_path('passkey-debug.log'), $e::class.': '.$e->getMessage().'
'.$e->getTraceAsString().'

', FILE_APPEND);
            // never leak attestation internals; single-use challenge consumed on pull
            throw ValidationException::withMessages([
                'credential' => ['The passkey registration could not be verified.'],
            ]);
        }

        return $key;
    }
}
