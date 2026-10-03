<?php

declare(strict_types=1);

use App\Contracts\TwoFactorPolicy;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use LaravelWebauthn\Models\WebauthnKey;
use Tests\Support\PasskeyFixture;

const ORIGIN = 'https://localhost';

beforeEach(function () {
    $this->seed(RolesSeeder::class);
});

function passkeyUser(): array
{
    $user = User::factory()->create([
        'email' => 'ada@example.com',
        'password' => 'Str0ng!Passw0rd',
    ]);

    return [$user, $user->createToken('web', ['*'])->plainTextToken];
}

/** register → returns [fixture, publicKeyOptions incl. user handle] */
function registerPasskey(string $token, string $name = 'Pixel'): array
{
    $options = test()->withToken($token)
        ->postJson('/api/v1/auth/passkeys/register/options')
        ->assertOk()->json('data.publicKey');

    $fixture = PasskeyFixture::generate();

    test()->withToken($token)->postJson('/api/v1/auth/passkeys/register', [
        'name' => $name,
        'credential' => $fixture->attestationPayload($options, ORIGIN),
    ])->assertCreated();

    return [$fixture, $options];
}

/* ─────────────────────── AC-001.15 registration ────────────────────────── */

test('register options returns FIDO2 creation options for rp = request host', function () {
    [, $token] = passkeyUser();

    $response = $this->withToken($token)->postJson('/api/v1/auth/passkeys/register/options');

    $response->assertOk()->assertJsonStructure([
        'data' => ['publicKey' => ['rp' => ['id', 'name'], 'user' => ['id', 'name', 'displayName'], 'challenge', 'pubKeyCredParams', 'attestation']],
    ]);
    expect($response->json('data.publicKey.rp.id'))->toBe('localhost')
        ->and($response->json('data.publicKey.attestation'))->toBe('none');
});

test('register stores the passkey and lists it back', function () {
    [$user, $token] = passkeyUser();
    [$fixture] = registerPasskey($token, 'Yubikey Left');

    expect(WebauthnKey::query()->where('user_id', $user->id)->count())->toBe(1);

    $list = $this->withToken($token)->getJson('/api/v1/auth/passkeys')->assertOk();
    $list->assertJsonPath('data.passkeys.0.name', 'Yubikey Left')
        ->assertJsonStructure(['data' => ['passkeys' => [['id', 'name', 'created_at']]]]);

    $this->withToken($token)->postJson('/api/v1/auth/passkeys/register', [
        'name' => 'X',
        'credential' => $fixture->attestationPayload(['rp' => ['id' => 'localhost'], 'challenge' => 'nope', 'user' => ['id' => 'AAA']], ORIGIN),
    ])->assertStatus(422)->assertJsonValidationErrors('credential'); // no live ceremony challenge
});

test('passkeys capped at 5 per user', function () {
    [, $token] = passkeyUser();

    for ($i = 1; $i <= 5; $i++) {
        registerPasskey($token, "key-{$i}");
    }

    $options = $this->withToken($token)->postJson('/api/v1/auth/passkeys/register/options')
        ->assertStatus(422)->assertJsonValidationErrors('passkeys');
});

/* ───────────────────── AC-001.16 assertion/login ───────────────────────── */

test('discoverable login: options without email, assertion issues device token', function () {
    [$user, $token] = passkeyUser();
    [$fixture, $registerOptions] = registerPasskey($token);
    $this->flushHeaders();
    $this->app['auth']->forgetGuards();

    $authOptions = $this->postJson('/api/v1/auth/passkeys/authenticate/options', [])->assertOk();
    $publicKey = $authOptions->json('data.publicKey');

    $login = $this->postJson('/api/v1/auth/passkeys/authenticate', [
        'credential' => $fixture->assertionPayload($publicKey, ORIGIN, $registerOptions['user']['id'], counter: 1),
        'device_type' => 'mobile',
    ]);

    $login->assertOk()->assertJsonStructure(['data' => ['token', 'device_type']]);
    $this->flushHeaders();
    $this->app['auth']->forgetGuards();
    $this->withToken($login->json('data.token'))->getJson('/api/v1/auth/me')
        ->assertOk()->assertJsonPath('data.email', 'ada@example.com');
    expect($user->tokens()->where('device_type', 'mobile')->count())->toBe(1);
});

test('options with email narrow allowCredentials; wrong/tampered/unknown credentials → generic 401', function () {
    [$user, $token] = passkeyUser();
    [$fixture, $registerOptions] = registerPasskey($token);
    $this->flushHeaders();
    $this->app['auth']->forgetGuards();

    $publicKey = $this->postJson('/api/v1/auth/passkeys/authenticate/options', ['email' => 'ada@example.com'])
        ->assertOk()->json('data.publicKey');
    expect($publicKey['allowCredentials'])->not->toBeEmpty()
        ->and($publicKey['allowCredentials'][0]['id'])->toBe(PasskeyFixture::b64url($fixture->credentialId()));

    // unknown credential id
    $this->postJson('/api/v1/auth/passkeys/authenticate', [
        'credential' => ['id' => 'AAAA', 'rawId' => 'AAAA', 'type' => 'public-key', 'response' => ['clientDataJSON' => base64_encode('{}'), 'authenticatorData' => base64_encode('x'), 'signature' => 'AAAA', 'userHandle' => null]],
        'device_type' => 'web',
    ])->assertStatus(401)->assertJsonPath('message', 'These credentials do not match our records.');

    // tampered signature
    $authOptions = $this->postJson('/api/v1/auth/passkeys/authenticate/options', [])->assertOk()->json('data.publicKey');
    $payload = $fixture->assertionPayload($authOptions, ORIGIN, $registerOptions['user']['id']);
    $payload['response']['signature'] = PasskeyFixture::b64url(str_repeat("\x01", 64));
    $this->postJson('/api/v1/auth/passkeys/authenticate', ['credential' => $payload, 'device_type' => 'web'])
        ->assertStatus(401);

    // replayed challenge: second assertion with the same options payload
    $authOptions2 = $this->postJson('/api/v1/auth/passkeys/authenticate/options', [])->assertOk()->json('data.publicKey');
    $fresh = $this->postJson('/api/v1/auth/passkeys/authenticate', [
        'credential' => $fixture->assertionPayload($authOptions2, ORIGIN, $registerOptions['user']['id'], counter: 2),
        'device_type' => 'web',
    ])->assertOk();
    expect($user->tokens()->where('device_type', 'web')->count())->toBe(1);

    $this->postJson('/api/v1/auth/passkeys/authenticate', [
        'credential' => $fixture->assertionPayload($authOptions2, ORIGIN, $registerOptions['user']['id'], counter: 3),
        'device_type' => 'desktop',
    ])->assertStatus(401)->assertJsonPath('message', 'These credentials do not match our records.');

    $this->flushHeaders();
    $this->app['auth']->forgetGuards();
    $this->withToken($fresh->json('data.token'))->getJson('/api/v1/auth/me')->assertOk();
});

test('options for unknown email still return a generic 200 challenge (no enumeration)', function () {
    $this->postJson('/api/v1/auth/passkeys/authenticate/options', ['email' => 'ghost@example.com'])
        ->assertOk()->assertJsonStructure(['data' => ['publicKey' => ['challenge']]]);
});

test('passkey login of an unverified user hits the same 403 gate as password login', function () {
    [$user, $token] = passkeyUser();
    [$fixture, $registerOptions] = registerPasskey($token);
    $user->forceFill(['email_verified_at' => null])->save();
    $this->flushHeaders();
    $this->app['auth']->forgetGuards();

    $publicKey = $this->postJson('/api/v1/auth/passkeys/authenticate/options', [])->assertOk()->json('data.publicKey');

    $this->postJson('/api/v1/auth/passkeys/authenticate', [
        'credential' => $fixture->assertionPayload($publicKey, ORIGIN, $registerOptions['user']['id']),
        'device_type' => 'web',
    ])->assertForbidden()->assertJsonPath('message', 'Please verify your email address.');
});

test('passkey assertion satisfies mandatory 2FA without any TOTP step', function () {
    [$user, $token] = passkeyUser();
    [$fixture, $registerOptions] = registerPasskey($token);
    $this->flushHeaders();
    $this->app['auth']->forgetGuards();

    $this->app->instance(TwoFactorPolicy::class, new class implements TwoFactorPolicy
    {
        public function requires(User $user): bool
        {
            return true;
        }
    });

    $publicKey = $this->postJson('/api/v1/auth/passkeys/authenticate/options', [])->assertOk()->json('data.publicKey');
    $this->postJson('/api/v1/auth/passkeys/authenticate', [
        'credential' => $fixture->assertionPayload($publicKey, ORIGIN, $registerOptions['user']['id']),
        'device_type' => 'web',
    ])->assertOk();
});

test('delete own passkey; foreign id 404', function () {
    [$user, $token] = passkeyUser();
    [$_, $opts] = registerPasskey($token);
    $keyId = $this->withToken($token)->getJson('/api/v1/auth/passkeys')->json('data.passkeys.0.id');

    $victim = User::factory()->create();
    $victimKey = new WebauthnKey(['user_id' => $victim->id, 'name' => 'steal', 'credentialId' => 'zz', 'type' => 'public-key', 'transports' => '[]', 'attestationType' => 'none', 'trustPath' => '[]', 'aaguid' => '00000000-0000-0000-0000-000000000000', 'credentialPublicKey' => 'AA', 'counter' => 0]);
    $victimKey->save();

    $this->withToken($token)->deleteJson('/api/v1/auth/passkeys/'.$victimKey->id)->assertNotFound();
    expect(WebauthnKey::query()->whereKey($victimKey->id)->exists())->toBeTrue();

    $this->withToken($token)->deleteJson('/api/v1/auth/passkeys/'.$keyId)->assertOk();
    expect(WebauthnKey::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('passkey whose owner was soft-deleted cannot authenticate (generic 401)', function () {
    [$user, $token] = passkeyUser();
    [$fixture, $registerOptions] = registerPasskey($token);
    $user->delete();
    $this->flushHeaders();
    $this->app['auth']->forgetGuards();

    $publicKey = $this->postJson('/api/v1/auth/passkeys/authenticate/options', [])->assertOk()->json('data.publicKey');
    $this->postJson('/api/v1/auth/passkeys/authenticate', [
        'credential' => $fixture->assertionPayload($publicKey, ORIGIN, $registerOptions['user']['id']),
        'device_type' => 'web',
    ])->assertStatus(401)->assertJsonPath('message', 'These credentials do not match our records.');
});
