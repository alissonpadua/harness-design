<?php

declare(strict_types=1);

namespace Tests\Support;

use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User;

/**
 * Hand-rolled Socialite fake (spec 006): avoids Mockery eval-class collisions
 * when multiple suites need Socialite in one process.
 */
class FakeSocialiteUser extends User
{
    public function __construct(string $id, string $email, string $name = 'Fake Oa')
    {
        $this->id = $id;
        $this->email = $email;
        $this->name = $name;
        $this->raw = ['email' => $email, 'email_verified' => true];
    }
}

final class FakeSocialite implements SocialiteFactory
{
    private FakeSocialiteProvider $provider;

    public function __construct(SocialiteUserContract $user)
    {
        $this->provider = new FakeSocialiteProvider($user);
    }

    public function driver($driver = null): Provider
    {
        return $this->provider;
    }
}

final class FakeSocialiteProvider extends AbstractProvider
{
    public function __construct(private readonly SocialiteUserContract $oaUser) {}

    public function stateless(): static
    {
        return $this;
    }

    public function user()
    {
        return $this->oaUser;
    }

    public function userFromToken($token)
    {
        return $this->oaUser;
    }

    protected function getAuthUrl($state)
    {
        return 'https://fake.test/auth';
    }

    protected function getTokenUrl(): string
    {
        return 'https://fake.test/token';
    }

    protected function getUserByToken($token): array
    {
        return [];
    }

    protected function mapUserToObject(array $user)
    {
        return $this->oaUser;
    }

    public function getScopes(): array
    {
        return [];
    }
}
