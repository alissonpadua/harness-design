<?php

declare(strict_types=1);

use App\Enums\DeviceType;
use App\Events\Auth\OtherLoginDetected;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->seed(RolesSeeder::class);
});

function verifiedUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'email' => 'ada@example.com',
        'password' => 'Str0ng!Passw0rd',
    ], $attributes));
}

function loginPayload(array $overrides = []): array
{
    return array_merge([
        'email' => 'ada@example.com',
        'password' => 'Str0ng!Passw0rd',
        'device_type' => 'web',
    ], $overrides);
}

/* ───────────────────────── AC-001.5 successful login ───────────────────── */

test('AC-001.5 login issues token bound to device type with request metadata', function () {
    verifiedUser();

    $response = $this->postJson('/api/v1/auth/login', loginPayload(), ['User-Agent' => 'BrunoTest/1.0']);

    $response->assertOk()->assertJsonStructure(['data' => ['token', 'device_type']]);
    expect($response->json('data.device_type'))->toBe('web');

    $token = User::first()->tokens()->first();
    expect($token->device_type)->toBe('web')
        ->and($token->name)->toBe('web')
        ->and($token->user_agent)->toContain('BrunoTest/1.0')
        ->and($token->ip_address)->not->toBeNull();
});

test('AC-001.5 re-login same device_type revokes only that type', function () {
    $user = verifiedUser();

    $first = $this->postJson('/api/v1/auth/login', loginPayload())->json('data.token');
    $this->postJson('/api/v1/auth/login', loginPayload(['device_type' => 'mobile']));

    $second = $this->postJson('/api/v1/auth/login', loginPayload())->json('data.token');

    expect($user->tokens()->count())->toBe(2);
    $this->withToken($first)->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->withToken($second)->getJson('/api/v1/auth/me')->assertOk();
});

test('AC-001.5 other_login.detected fires only when another device type had an active session', function () {
    Event::fake([OtherLoginDetected::class]);
    verifiedUser();

    $this->postJson('/api/v1/auth/login', loginPayload(['device_type' => 'web']));
    Event::assertNotDispatched(OtherLoginDetected::class);

    $this->postJson('/api/v1/auth/login', loginPayload(['device_type' => 'mobile']));
    Event::assertDispatchedTimes(OtherLoginDetected::class, 1);
});

test('AC-001.5 invalid device_type is a 422', function () {
    verifiedUser();
    $this->postJson('/api/v1/auth/login', loginPayload(['device_type' => 'toaster']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('device_type');
});

/* ────────────────────── AC-001.6/.7 denial parity matrix ───────────────── */

test('AC-001.6 wrong password and unknown email return byte-identical 401', function () {
    verifiedUser();

    $wrongPassword = $this->postJson('/api/v1/auth/login', loginPayload(['password' => 'Wr0ng!Password1']));
    $unknownEmail = $this->postJson('/api/v1/auth/login', loginPayload(['email' => 'ghost@example.com']));

    $wrongPassword->assertStatus(401)->assertJsonPath('message', 'These credentials do not match our records.');
    expect($unknownEmail->getContent())->toBe($wrongPassword->getContent());
});

test('AC-001.7 soft-deleted user gets the identical generic denial', function () {
    $user = verifiedUser();
    $user->delete();

    $denied = $this->postJson('/api/v1/auth/login', loginPayload());
    $unknown = $this->postJson('/api/v1/auth/login', loginPayload(['email' => 'ghost@example.com']));

    $denied->assertStatus(401);
    expect($denied->getContent())->toBe($unknown->getContent());
});

test('AC-001.7 restoring a soft-deleted user re-enables login (admin-only path, no user endpoint)', function () {
    $user = verifiedUser();
    $user->delete();
    expect($this->postJson('/api/v1/auth/login', loginPayload())->status())->toBe(401);

    User::withTrashed()->find($user->id)->restore();
    $this->postJson('/api/v1/auth/login', loginPayload())->assertOk();
});

test('AC-001.3 valid credentials but unverified email → 403 verify message, no token', function () {
    User::factory()->unverified()->create(['email' => 'ada@example.com', 'password' => 'Str0ng!Passw0rd']);

    $this->postJson('/api/v1/auth/login', loginPayload())
        ->assertForbidden()
        ->assertJsonPath('message', 'Please verify your email address.');

    expect(User::first()->tokens()->count())->toBe(0);
});

/* ───────────────────────── me / logout / logout-all ─────────────────────── */

test('auth me returns profile envelope and 401 unauthenticated JSON without token', function () {
    $user = verifiedUser();
    $token = $this->postJson('/api/v1/auth/login', loginPayload())->json('data.token');

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', 'ada@example.com')
        ->assertJsonPath('data.name', $user->name);

    $this->flushHeaders();
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
});

test('logout revokes current token only; logout-all revokes every device', function () {
    $user = verifiedUser();
    $web = $this->postJson('/api/v1/auth/login', loginPayload(['device_type' => 'web']))->json('data.token');
    $mobile = $this->postJson('/api/v1/auth/login', loginPayload(['device_type' => 'mobile']))->json('data.token');

    $this->withToken($web)->postJson('/api/v1/auth/logout')
        ->assertOk()->assertExactJson(['data' => ['revoked' => true]]);

    expect($user->tokens()->count())->toBe(1);
    $this->flushHeaders();
    $this->app['auth']->forgetGuards();
    $this->withToken($web)->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->withToken($mobile)->getJson('/api/v1/auth/me')->assertOk();

    $this->withToken($mobile)->postJson('/api/v1/auth/logout-all')
        ->assertOk()->assertJsonStructure(['data' => ['revoked_count']]);

    expect($user->tokens()->count())->toBe(0);
});

/* ─────────────────────────── sessions (S2) ─────────────────────────────── */

test('sessions list exposes per-device metadata and flags the current one', function () {
    verifiedUser();
    $web = $this->postJson('/api/v1/auth/login', loginPayload(['device_type' => 'web']))->json('data.token');
    $this->postJson('/api/v1/auth/login', loginPayload(['device_type' => 'mobile']));

    $response = $this->withToken($web)->getJson('/api/v1/auth/sessions')->assertOk();

    $sessions = $response->json('data.sessions');
    expect($sessions)->toHaveCount(2)
        ->and($sessions[0]['device_type'])->toBeString()
        ->and($sessions[0])->toHaveKeys(['id', 'device_type', 'ip_address', 'user_agent', 'created_at', 'current']);
});

test('deleting another session revokes it; deleting the current session kills own access', function () {
    $user = verifiedUser();
    $web = $this->postJson('/api/v1/auth/login', loginPayload(['device_type' => 'web']))->json('data.token');
    $mobile = $this->postJson('/api/v1/auth/login', loginPayload(['device_type' => 'mobile']))->json('data.token');

    $sessions = $this->withToken($web)->getJson('/api/v1/auth/sessions')->json('data.sessions');
    $mobileId = collect($sessions)->firstWhere('current', false)['id'];

    $this->withToken($web)->deleteJson("/api/v1/auth/sessions/{$mobileId}")
        ->assertOk()->assertExactJson(['data' => ['revoked' => true]]);

    expect($user->tokens()->count())->toBe(1);
    $this->flushHeaders();
    $this->app['auth']->forgetGuards();
    $this->withToken($mobile)->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->flushHeaders();
    $this->app['auth']->forgetGuards();

    $webId = $this->withToken($web)->getJson('/api/v1/auth/sessions')->json('data.sessions.0.id');
    $this->withToken($web)->deleteJson("/api/v1/auth/sessions/{$webId}")->assertOk();
    $this->flushHeaders();
    $this->app['auth']->forgetGuards();
    $this->withToken($web)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

test('deleting a foreign or unknown session id is a 404/403, never someone elses token', function () {
    verifiedUser();
    $web = $this->postJson('/api/v1/auth/login', loginPayload())->json('data.token');

    $victim = User::factory()->create();
    $victimToken = $victim->createToken('stealth', ['*'])->accessToken;

    $this->withToken($web)->deleteJson('/api/v1/auth/sessions/'.$victimToken->id)
        ->assertStatus(404);

    expect($victim->tokens()->count())->toBe(1);
});

/* ───────────────── TrackTokenUsage throttled bookkeeping (AC-001.8) ────── */

test('last_used_at is stamped and refreshed no more than once per 5 minutes', function () {
    verifiedUser();
    $token = $this->postJson('/api/v1/auth/login', loginPayload())->json('data.token');

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    $pat = User::first()->tokens()->first();
    $firstUse = $pat->last_used_at;
    expect($firstUse)->not->toBeNull();

    $this->travel(1)->minutes();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    expect(User::first()->tokens()->first()->last_used_at->equalTo($firstUse))->toBeTrue();

    $this->travel(6)->minutes();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    expect(User::first()->tokens()->first()->last_used_at->greaterThan($firstUse))->toBeTrue();
});

test('device type enum is the single source for validation', function () {
    expect(DeviceType::cases())->toHaveCount(4)
        ->and(array_map(fn (DeviceType $c) => $c->value, DeviceType::cases()))
        ->toBe(['web', 'mobile', 'desktop', 'cli']);
});
