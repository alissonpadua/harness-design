<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Auth\TwoFactorChallenge;
use App\Contracts\TwoFactorPolicy;
use App\Data\Auth\CompleteOAuthData;
use App\Data\Auth\LoginTokenData;
use App\Events\Auth\UserRegistered;
use App\Exceptions\EmailNotVerifiedException;
use App\Exceptions\LoginFailedException;
use App\Exceptions\TwoFactorMandatoryException;
use App\Models\OauthAccount;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Spec 001 AC-001.12–.14. Code-exchange flow (no server-side session/state:
 * stateless providers, SPA/mobile clients hold the state themselves).
 */
final readonly class CompleteOAuthAction
{
    public function __construct(
        private IssueDeviceTokenAction $issueToken,
        private TwoFactorChallenge $challenge,
        private TwoFactorPolicy $policy,
    ) {}

    public function handle(CompleteOAuthData $data, string $ip, ?string $userAgent): LoginTokenData
    {
        if (! in_array($data->provider, config('auth.oauth.providers'), true)) {
            throw new NotFoundHttpException;
        }

        // Socialite reads the exchange `code` from the request; inject it (token/API flow).
        app('request')->query->set('code', $data->code);

        $driver = Socialite::driver($data->provider);
        assert($driver instanceof AbstractProvider); // all v5 providers extend it (stateless lives there)
        $identity = $driver->stateless()->user();
        assert($identity instanceof SocialiteUser);

        $emailVerified = $data->provider === 'google'
            && (bool) ($identity->getRaw()['email_verified'] ?? false); // decision #2: facebook never trusted

        $email = $identity->getEmail() !== null && $identity->getEmail() !== ''
            ? strtolower((string) $identity->getEmail())
            : null;

        $name = is_string($identity->getName()) && $identity->getName() !== '' ? $identity->getName() : (string) $email;
        $user = $this->resolveUser($data->provider, (string) $identity->getId(), $email, $emailVerified, $name);

        if (! $user->hasVerifiedEmail()) {
            throw new EmailNotVerifiedException;
        }

        if ($this->policy->requires($user) && $user->two_factor_confirmed_at === null) {
            throw new TwoFactorMandatoryException;
        }

        $this->challenge->verify($user, $data->otp);

        return $this->issueToken->handle($user, $data->device_type, $ip, $userAgent);
    }

    private function resolveUser(string $provider, string $providerId, ?string $email, bool $emailVerified, string $name): User
    {
        /** @var OauthAccount|null $account */
        $account = OauthAccount::query()
            ->where('provider', $provider)
            ->where('provider_id', $providerId)
            ->first();

        if ($account !== null) {
            /** @var User|null $user */
            $user = User::query()->find($account->user_id);

            if ($user === null) {
                throw new LoginFailedException; // soft-deleted owner (AC-001.14)
            }

            return $user;
        }

        if ($email === null) {
            throw new LoginFailedException; // cannot map identity to an account without email
        }

        /** @var User|null $existing */
        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            if ($emailVerified && $existing->hasVerifiedEmail()) {
                $this->link($existing, $provider, $providerId, $email, $emailVerified);

                return $existing;
            }

            // email claimed by an account we cannot safely link (provider unverified or
            // local email unverified) → same generic response, no enumeration, no duplicate.
            throw new EmailNotVerifiedException;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Str::password(32),
        ]);
        $user->forceFill(['email_verified_at' => $emailVerified ? now() : null])->save();
        $user->assignRole(config('auth.oauth.default_role'));
        $this->link($user, $provider, $providerId, $email, $emailVerified);

        event(new UserRegistered($user));

        return $user;
    }

    private function link(User $user, string $provider, string $providerId, ?string $email, bool $verified): void
    {
        OauthAccount::create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_id' => $providerId,
            'provider_email' => $email,
            'provider_email_verified' => $verified,
        ]);
    }
}
