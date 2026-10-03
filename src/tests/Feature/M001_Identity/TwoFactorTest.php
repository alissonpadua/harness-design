<?php

declare(strict_types=1);

use App\Contracts\TwoFactorPolicy;
use App\Events\Auth\RecoveryCodeUsed;
use App\Events\Auth\TwoFactorDisabled;
use App\Events\Auth\TwoFactorEnabled;
use App\Models\AuthLink;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Event;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->seed(RolesSeeder::class);
});

function userWithLogin(array $overrides = []): array
{
    $user = User::factory()->create(array_merge([
        'email' => 'ada@example.com',
        'password' => 'Str0ng!Passw0rd',
    ], $overrides));

    $token = $user->createToken('web', ['*'])->plainTextToken;

    return [$user, $token];
}

function totpFor(string $secret): string
{
    return (new Google2FA)->getCurrentOtp($secret);
}

/* ─────────────────────────── AC-001.17 enroll/confirm ──────────────────── */

test('enroll returns secret + otpauth uri + qr, stored encrypted but unconfirmed', function () {
    [, $token] = userWithLogin();

    $response = $this->withToken($token)->postJson('/api/v1/auth/2fa/enroll');

    $response->assertOk()->assertJsonStructure(['data' => ['secret', 'provisioning_uri', 'qr_data_url']]);
    expect($response->json('data.provisioning_uri'))->toStartWith('otpauth://totp/')
        ->and($response->json('data.qr_data_url'))->toStartWith('data:image/svg+xml;base64,');

    $user = User::first();
    $raw = $user->getRawOriginal('two_factor_secret');
    expect($raw)->not->toBe($user->two_factor_secret) // encrypted at rest
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->tokens()->count())->toBe(1);

    $this->flushHeaders();
    $this->app['auth']->forgetGuards();
    $this->postJson('/api/v1/auth/2fa/enroll')->assertUnauthorized();
});

test('confirm activates 2FA, returns 8 plaintext recovery codes, revokes other devices, fires event', function () {
    Event::fake([TwoFactorEnabled::class]);
    [$user, $token] = userWithLogin();
    $this->withToken($token)->postJson('/api/v1/auth/2fa/enroll');
    $secret = $user->refresh()->two_factor_secret;
    $other = $user->createToken('mobile', ['*']);

    $response = $this->withToken($token)->postJson('/api/v1/auth/2fa/confirm', ['code' => totpFor($secret)]);

    $response->assertOk()
        ->assertJsonStructure(['data' => ['recovery_codes']])
        ->assertJsonCount(8, 'data.recovery_codes');

    expect($user->refresh()->two_factor_confirmed_at)->not->toBeNull()
        ->and($user->two_factor_recovery_codes)->toHaveCount(8)
        ->and($user->tokens()->where('device_type', 'mobile')->count())->toBe(0)
        ->and($user->tokens()->count())->toBe(1); // current survives

    Event::assertDispatched(TwoFactorEnabled::class);
});

test('confirm with a wrong code is a 422 and leaves 2FA unconfirmed', function () {
    [, $token] = userWithLogin();
    $this->withToken($token)->postJson('/api/v1/auth/2fa/enroll');

    $this->withToken($token)->postJson('/api/v1/auth/2fa/confirm', ['code' => '000000'])
        ->assertStatus(422)->assertJsonValidationErrors('code');

    expect(User::first()->two_factor_confirmed_at)->toBeNull();
});

/* ────────────────────────── AC-001.18 login matrix ─────────────────────── */

test('login after confirmed 2FA: missing otp → 401 required, invalid → 422, valid → token', function () {
    [$user, $enrollToken] = userWithLogin();
    $this->withToken($enrollToken)->postJson('/api/v1/auth/2fa/enroll');
    $secret = $user->refresh()->two_factor_secret;
    $this->withToken($enrollToken)->postJson('/api/v1/auth/2fa/confirm', ['code' => totpFor($secret)]);
    $this->flushHeaders();
    $this->app['auth']->forgetGuards();

    $before = $user->tokens()->count();
    $noOtp = $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'Str0ng!Passw0rd', 'device_type' => 'web']);
    $noOtp->assertStatus(401)->assertJsonPath('message', 'Two factor authentication is required.');
    expect($user->tokens()->count())->toBe($before); // failed challenge issues no token

    $wrong = $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'Str0ng!Passw0rd', 'device_type' => 'web', 'otp' => '111111']);
    $wrong->assertStatus(422)->assertJsonValidationErrors('otp');

    $ok = $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'Str0ng!Passw0rd', 'device_type' => 'web', 'otp' => totpFor($secret)]);
    $ok->assertOk()->assertJsonStructure(['data' => ['token']]);
});

test('recovery code logs in once, is consumed, second use rejected, event fired', function () {
    Event::fake([RecoveryCodeUsed::class]);
    [$user, $enrollToken] = userWithLogin();
    $this->withToken($enrollToken)->postJson('/api/v1/auth/2fa/enroll');
    $secret = $user->refresh()->two_factor_secret;
    $codes = $this->withToken($enrollToken)->postJson('/api/v1/auth/2fa/confirm', ['code' => totpFor($secret)])->json('data.recovery_codes');
    $this->flushHeaders();
    $this->app['auth']->forgetGuards();

    $login = $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'Str0ng!Passw0rd', 'device_type' => 'web', 'otp' => $codes[0]]);
    $login->assertOk();
    Event::assertDispatched(RecoveryCodeUsed::class);
    expect($user->refresh()->two_factor_recovery_codes)->toHaveCount(7);

    $this->flushHeaders();
    $this->app['auth']->forgetGuards();
    $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'Str0ng!Passw0rd', 'device_type' => 'desktop', 'otp' => $codes[0]])
        ->assertStatus(422)->assertJsonValidationErrors('otp');
});

/* ───────────────────────────── disable ─────────────────────────────────── */

test('disable requires current password, clears state, fires event; login stops asking for otp', function () {
    Event::fake([TwoFactorDisabled::class]);
    [$user, $enrollToken] = userWithLogin();
    $this->withToken($enrollToken)->postJson('/api/v1/auth/2fa/enroll');
    $secret = $user->refresh()->two_factor_secret;
    $this->withToken($enrollToken)->postJson('/api/v1/auth/2fa/confirm', ['code' => totpFor($secret)]);
    $this->flushHeaders();
    $this->app['auth']->forgetGuards();
    $liveToken = $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'Str0ng!Passw0rd', 'device_type' => 'web', 'otp' => totpFor($secret)])->json('data.token');

    $this->withToken($liveToken)->postJson('/api/v1/auth/2fa/disable', ['password' => 'Wr0ng!Password1'])
        ->assertStatus(422)->assertJsonValidationErrors('password');
    expect(User::first()->two_factor_confirmed_at)->not->toBeNull();

    $this->withToken($liveToken)->postJson('/api/v1/auth/2fa/disable', ['password' => 'Str0ng!Passw0rd'])
        ->assertOk()->assertExactJson(['data' => ['disabled' => true]]);

    $u = User::first();
    expect($u->two_factor_secret)->toBeNull()
        ->and($u->two_factor_confirmed_at)->toBeNull()
        ->and($u->two_factor_recovery_codes)->toBeNull();
    Event::assertDispatched(TwoFactorDisabled::class);

    $this->flushHeaders();
    $this->app['auth']->forgetGuards();
    $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'Str0ng!Passw0rd', 'device_type' => 'web'])
        ->assertOk();
});

/* ────────────────────── AC-001.19 mandatory policy ─────────────────────── */

test('enforced policy: unenrolled → 403 enroll-first; enrolled → normal challenge', function () {
    [$user, $token] = userWithLogin();
    $this->withToken($token)->postJson('/api/v1/auth/2fa/enroll');
    $secret = $user->refresh()->two_factor_secret;
    $this->withToken($token)->postJson('/api/v1/auth/2fa/confirm', ['code' => totpFor($secret)]);
    $this->flushHeaders();
    $this->app['auth']->forgetGuards();

    // enforce for everyone
    $this->app->instance(TwoFactorPolicy::class, new class implements TwoFactorPolicy
    {
        public function requires(User $user): bool
        {
            return true;
        }
    });

    $fresh = User::factory()->create(['email' => 'new@example.com', 'password' => 'Str0ng!Passw0rd']);

    $blocked = $this->postJson('/api/v1/auth/login', ['email' => 'new@example.com', 'password' => 'Str0ng!Passw0rd', 'device_type' => 'web']);
    $blocked->assertStatus(403)
        ->assertJsonPath('message', 'Two factor authentication is mandatory for your organization.')
        ->assertJsonPath('errors.two_factor', ['required']);
    expect($fresh->tokens()->count())->toBe(0);

    // enrolled user passes the mandate and lands on the otp challenge instead
    $challenge = $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'Str0ng!Passw0rd', 'device_type' => 'web']);
    $challenge->assertStatus(401)->assertJsonPath('message', 'Two factor authentication is required.');
});

/* ───────────── AC-001.18 cross-surface: magic link respects 2FA ────────── */

test('magic consume with 2FA: missing otp 401 leaves link unconsumed; valid otp consumes', function () {
    [$user, $token] = userWithLogin();
    $this->withToken($token)->postJson('/api/v1/auth/2fa/enroll');
    $secret = $user->refresh()->two_factor_secret;
    $confirm = $this->withToken($token)->postJson('/api/v1/auth/2fa/confirm', ['code' => totpFor($secret)]);
    $codes = $confirm->json('data.recovery_codes');
    $this->flushHeaders();
    $this->app['auth']->forgetGuards();

    $link = AuthLink::issue($user, 'magic_link');
    $rawMagic = $link->token;

    $missing = $this->postJson('/api/v1/auth/magic-link/consume', ['token' => $rawMagic, 'device_type' => 'desktop']);
    $missing->assertStatus(401)->assertJsonPath('message', 'Two factor authentication is required.');
    expect($link->refresh()->used_at)->toBeNull(); // link survives a failed challenge

    $this->postJson('/api/v1/auth/magic-link/consume', ['token' => $rawMagic, 'device_type' => 'desktop', 'otp' => $codes[1]])
        ->assertOk()->assertJsonStructure(['data' => ['token']]);
    expect($link->refresh()->used_at)->not->toBeNull();
});

test('mandatory policy also guards the magic-link surface (enroll-first 403, link survives)', function () {
    $user = User::factory()->unverified()->create(['email' => 'ada@example.com', 'password' => 'Str0ng!Passw0rd']);

    $this->app->instance(TwoFactorPolicy::class, new class implements TwoFactorPolicy
    {
        public function requires(User $user): bool
        {
            return true;
        }
    });

    $link = AuthLink::issue($user, 'magic_link');
    $raw = $link->token;

    $this->postJson('/api/v1/auth/magic-link/consume', ['token' => $raw, 'device_type' => 'web'])
        ->assertStatus(403)
        ->assertJsonPath('errors.two_factor', ['required']);

    expect($link->refresh()->used_at)->toBeNull()
        ->and($user->refresh()->email_verified_at)->toBeNull();
});
