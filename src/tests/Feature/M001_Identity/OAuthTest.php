<?php

declare(strict_types=1);

use App\Contracts\TwoFactorPolicy;
use App\Events\Auth\UserRegistered;
use App\Models\OauthAccount;
use App\Models\User;
use App\Notifications\EmailVerificationNotification;
use App\Notifications\ResetPasswordNotification;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Symfony\Component\HttpFoundation\RedirectResponse;

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    config(['services.google.client_id' => 'gid', 'services.google.client_secret' => 'gsec']);
    config(['services.facebook.client_id' => 'fid', 'services.facebook.client_secret' => 'fsec']);
});

function socialiteUser(string $id, ?string $email, bool $emailVerified, string $name = 'Ada L'): Mockery\MockInterface&SocialiteUserContract
{
    $u = Mockery::mock(SocialiteUser::class);
    $u->shouldReceive('getId')->andReturn($id);
    $u->shouldReceive('getEmail')->andReturn($email);
    $u->shouldReceive('getName')->andReturn($name);
    $u->shouldReceive('getRaw')->andReturn(['email' => $email, 'email_verified' => $emailVerified]);

    return $u;
}

function expectSocialiteExchange(string $provider, string $code, Mockery\MockInterface&SocialiteUserContract $user): void
{
    $driver = Mockery::mock(AbstractProvider::class);
    $driver->shouldReceive('stateless')->andReturnSelf()->shouldReceive('user')->andReturn($user);
    Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
}

function exchangePayload(string $provider, array $overrides = []): array
{
    return array_merge(['code' => 'code-123', 'device_type' => 'web'], $overrides);
}

/* ─────────────────────────── AC-001.12 providers ───────────────────────── */

test('unknown provider rejected with 404 on both endpoints', function () {
    $this->postJson('/api/v1/auth/oauth/github/redirect')->assertNotFound();
    $this->postJson('/api/v1/auth/oauth/github/exchange', exchangePayload('github'))->assertNotFound();
});

test('redirect endpoint returns provider authorize url', function () {
    $response = Mockery::mock(RedirectResponse::class);
    $response->shouldReceive('getTargetUrl')->andReturn('https://accounts.google.com/o/oauth2/v2/auth?state=x');
    $driver = Mockery::mock(AbstractProvider::class);
    $driver->shouldReceive('stateless->redirect')->andReturn($response);
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

    $this->postJson('/api/v1/auth/oauth/google/redirect')
        ->assertOk()
        ->assertJsonPath('data.url', 'https://accounts.google.com/o/oauth2/v2/auth?state=x');
});

/* ─────────────────────────── AC-001.13 matching ────────────────────────── */

test('known provider identity logs straight in with device token', function () {
    $user = User::factory()->create(['email' => 'ada@example.com', 'password' => 'Str0ng!Passw0rd']);
    OauthAccount::query()->insert(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'g-111', 'provider_email' => 'ada@example.com', 'provider_email_verified' => true, 'created_at' => now(), 'updated_at' => now()]);

    expectSocialiteExchange('google', 'code-123', socialiteUser('g-111', 'ada@example.com', true));

    $this->postJson('/api/v1/auth/oauth/google/exchange', exchangePayload('google'))
        ->assertOk()->assertJsonStructure(['data' => ['token', 'device_type']]);

    expect($user->tokens()->where('device_type', 'web')->count())->toBe(1);
});

test('verified google email on existing password account links and logs in (no new user)', function () {
    $user = User::factory()->create(['email' => 'ada@example.com', 'password' => 'Str0ng!Passw0rd']);
    expectSocialiteExchange('google', 'code-123', socialiteUser('g-222', 'ada@example.com', true));

    $this->postJson('/api/v1/auth/oauth/google/exchange', exchangePayload('google'))->assertOk();

    expect(User::count())->toBe(1)
        ->and(OauthAccount::query()->where('provider_id', 'g-222')->where('user_id', $user->id)->exists())->toBeTrue();
});

test('new google email creates unverified-false user (claim honored), assigns role, fires event, logs in', function () {
    Event::fake([UserRegistered::class]);
    expectSocialiteExchange('google', 'code-123', socialiteUser('g-333', 'new@example.com', true));

    $this->postJson('/api/v1/auth/oauth/google/exchange', exchangePayload('google'))->assertOk();

    $user = User::whereEmail('new@example.com')->firstOrFail();
    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->tokens()->count())->toBe(1)
        ->and($user->getRoleNames())->toContain('user');
    Event::assertDispatched(UserRegistered::class);
});

test('new facebook email creates UNVERIFIED user → 403 verify gate + verification queued, no token', function () {
    Notification::fake();
    expectSocialiteExchange('facebook', 'code-123', socialiteUser('fb-1', 'fb@example.com', true)); // provider claim must be ignored

    $this->postJson('/api/v1/auth/oauth/facebook/exchange', exchangePayload('facebook'))
        ->assertForbidden()->assertJsonPath('message', 'Please verify your email address.');

    $user = User::whereEmail('fb@example.com')->firstOrFail();
    expect($user->email_verified_at)->toBeNull()->and($user->tokens()->count())->toBe(0);
    Notification::assertSentTo($user, EmailVerificationNotification::class);
});

test('facebook identity whose email matches an existing UNVERIFIED account does not link nor duplicate', function () {
    $user = User::factory()->unverified()->create(['email' => 'ada@example.com']);
    expectSocialiteExchange('facebook', 'code-123', socialiteUser('fb-2', 'ada@example.com', true));

    $this->postJson('/api/v1/auth/oauth/facebook/exchange', exchangePayload('facebook'))->assertForbidden();

    expect(User::count())->toBe(1)
        ->and(OauthAccount::query()->count())->toBe(0);
    unset($user);
});

/* ───────────── AC-001.14 denials + password-less + gates ───────────────── */

test('password-less account can set one via forgot-password flow (indistinguishable acceptance)', function () {
    expectSocialiteExchange('google', 'code-123', socialiteUser('g-444', 'pass@example.com', true));
    $this->postJson('/api/v1/auth/oauth/google/exchange', exchangePayload('google'))->assertOk();

    Notification::fake();
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'pass@example.com'])
        ->assertStatus(202)->assertExactJson(['data' => ['accepted' => true]]);
    Notification::assertSentTo(User::whereEmail('pass@example.com')->firstOrFail(), ResetPasswordNotification::class);
});

test('linked to soft-deleted account → generic 401, no resurrection, no new link row', function () {
    $user = User::factory()->create(['email' => 'gone@example.com']);
    $user->delete();
    OauthAccount::query()->insert(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'g-555', 'provider_email' => 'gone@example.com', 'provider_email_verified' => true, 'created_at' => now(), 'updated_at' => now()]);

    expectSocialiteExchange('google', 'code-123', socialiteUser('g-555', 'gone@example.com', true));

    $this->postJson('/api/v1/auth/oauth/google/exchange', exchangePayload('google'))
        ->assertStatus(401)->assertJsonPath('message', 'These credentials do not match our records.');
    expect(User::count())->toBe(0);
});

test('provider giving no email → generic 401, nothing created', function () {
    expectSocialiteExchange('google', 'code-123', socialiteUser('g-666', null, true));

    $this->postJson('/api/v1/auth/oauth/google/exchange', exchangePayload('google'))->assertStatus(401);
    expect(User::count())->toBe(0);
});

test('oauth exchange honours 2FA: existing totp-required 401, valid otp passes, mandatory policy 403', function () {
    $user = User::factory()->create(['email' => 'ada@example.com', 'password' => 'Str0ng!Passw0rd']);
    $user->forceFill([
        'two_factor_secret' => 'SEC',
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => ['not-used'],
    ])->save();
    OauthAccount::query()->insert(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'g-777', 'provider_email' => 'ada@example.com', 'provider_email_verified' => true, 'created_at' => now(), 'updated_at' => now()]);
    expectSocialiteExchange('google', 'code-123', socialiteUser('g-777', 'ada@example.com', true));

    $this->postJson('/api/v1/auth/oauth/google/exchange', exchangePayload('google'))
        ->assertStatus(401)->assertJsonPath('message', 'Two factor authentication is required.');

    unset($user);
});

test('oauth exchange under mandatory 2FA policy without enrollment → 403, no token', function () {
    $plain = User::factory()->create(['email' => 'plain@example.com']);
    $this->app->instance(TwoFactorPolicy::class, new class implements TwoFactorPolicy
    {
        public function requires(User $user): bool
        {
            return true;
        }
    });
    expectSocialiteExchange('google', 'code-456', socialiteUser('g-888', 'plain@example.com', true));

    $this->postJson('/api/v1/auth/oauth/google/exchange', exchangePayload('google'))
        ->assertStatus(403)->assertJsonPath('message', 'Two factor authentication is mandatory for your organization.');

    expect($plain->tokens()->count())->toBe(0);
});

test('exchange validates device_type and code before provider roundtrip', function () {
    $this->postJson('/api/v1/auth/google/exchange', [])->assertNotFound(); // malformed path
    $this->postJson('/api/v1/auth/oauth/google/exchange', ['device_type' => 'toaster'])
        ->assertStatus(422)->assertJsonValidationErrors(['code', 'device_type']);
});
