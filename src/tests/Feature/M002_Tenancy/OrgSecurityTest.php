<?php

declare(strict_types=1);

use App\Contracts\TwoFactorPolicy;
use App\Enums\OrgRole;
use App\Events\Org\OwnershipTransferred;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    Notification::fake();
});

function m006As(?string $token)
{
    test()->flushHeaders();
    app('auth')->forgetGuards();

    return $token === null ? test() : test()->withToken($token);
}

function orgWithPolicy(bool $require2fa): array
{
    $ada = User::factory()->create(['email' => 'ada@example.com', 'password' => 'Str0ng!Passw0rd']);
    $org = Organization::create([
        'name' => 'SecureCo', 'owner_id' => $ada->id, 'type' => 'team', 'require_2fa' => $require2fa,
    ]);
    $org->memberships()->create(['user_id' => $ada->id, 'role' => 'owner', 'status' => 'active']);
    $ada->forceFill(['current_organization_id' => $org->id])->save();

    return [$ada, $org];
}

/* ───────────────────── AC-002.18 org 2FA enforcement ───────────────────── */

test('require_2fa org: unenrolled member is enroll-blocked at login', function () {
    [$ada, $org] = orgWithPolicy(true);

    $response = m006As(null)->postJson('/api/v1/auth/login', [
        'email' => 'ada@example.com', 'password' => 'Str0ng!Passw0rd', 'device_type' => 'web',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('message', 'Two factor authentication is mandatory for your organization.')
        ->assertJsonPath('errors.two_factor', ['required']);

    expect($ada->tokens()->count())->toBe(0);
});

test('require_2fa org: enrolled member faces the normal otp challenge; non-member org unaffected', function () {
    [$ada, $org] = orgWithPolicy(true);
    $secret = (new Google2FA)->generateSecretKey(32);
    $ada->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => ['x']])->save();

    m006As(null)->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'Str0ng!Passw0rd', 'device_type' => 'web'])
        ->assertStatus(401)->assertJsonPath('message', 'Two factor authentication is required.');

    m006As(null)->postJson('/api/v1/auth/login', [
        'email' => 'ada@example.com', 'password' => 'Str0ng!Passw0rd', 'device_type' => 'web',
        'otp' => (new Google2FA)->getCurrentOtp($secret),
    ])->assertOk();

    // policy instance is org-bound: a user with no org passes
    $lone = User::factory()->create(['email' => 'lone@example.com', 'password' => 'Str0ng!Passw0rd']);
    expect(app(TwoFactorPolicy::class)->requires($lone))->toBeFalse();
    m006As(null)->postJson('/api/v1/auth/login', ['email' => 'lone@example.com', 'password' => 'Str0ng!Passw0rd', 'device_type' => 'desktop'])->assertOk();

    // suspended members are not forced by the org policy (they are locked out elsewhere)
    $org->memberships()->where('user_id', $ada->id)->update(['status' => 'suspended']);
    expect(app(TwoFactorPolicy::class)->requires($ada->refresh()))->toBeFalse();
});

/* ─────────────────────── AC-002.9 ownership transfer ───────────────────── */

test('transfer ownership: role swap, owner pointer, event; guards on password/target/role', function () {
    Event::fake([OwnershipTransferred::class]);
    [$ada, $org] = orgWithPolicy(false);
    $bea = User::factory()->create(['email' => 'bea@example.com', 'password' => 'Str0ng!Passw0rd']);
    $org->memberships()->create(['user_id' => $bea->id, 'role' => 'member', 'status' => 'active']);
    $token = $ada->createToken('web', ['*'])->plainTextToken;

    // wrong password
    m006As($token)->postJson('/api/v1/orgs/'.$org->id.'/transfer-ownership', ['to_user_id' => $bea->id, 'current_password' => 'Nope1!aaaa'])
        ->assertStatus(422)->assertJsonValidationErrors('current_password');

    // target not a member
    $stranger = User::factory()->create();
    m006As($token)->postJson('/api/v1/orgs/'.$org->id.'/transfer-ownership', ['to_user_id' => $stranger->id, 'current_password' => 'Str0ng!Passw0rd'])
        ->assertStatus(422)->assertJsonValidationErrors('to_user_id');

    // happy path
    m006As($token)->postJson('/api/v1/orgs/'.$org->id.'/transfer-ownership', ['to_user_id' => $bea->id, 'current_password' => 'Str0ng!Passw0rd'])
        ->assertOk()->assertJsonPath('data.transferred', true);

    expect($org->refresh()->owner_id)->toBe($bea->id)
        ->and($org->membershipFor($bea)->role)->toBe(OrgRole::Owner)
        ->and($org->membershipFor($ada)->role)->toBe(OrgRole::Admin);
    Event::assertDispatched(OwnershipTransferred::class);

    // former owner is now admin: cannot transfer anymore
    m006As($token)->postJson('/api/v1/orgs/'.$org->id.'/transfer-ownership', ['to_user_id' => $ada->id, 'current_password' => 'Str0ng!Passw0rd'])
        ->assertForbidden();
});

test('transfer requires otp when actor has 2FA confirmed', function () {
    [$ada, $org] = orgWithPolicy(false);
    $secret = (new Google2FA)->generateSecretKey(32);
    $ada->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
    $bea = User::factory()->create(['email' => 'bea@example.com']);
    $org->memberships()->create(['user_id' => $bea->id, 'role' => 'member', 'status' => 'active']);
    $token = $ada->createToken('web', ['*'])->plainTextToken;

    m006As($token)->postJson('/api/v1/orgs/'.$org->id.'/transfer-ownership', ['to_user_id' => $bea->id, 'current_password' => 'Str0ng!Passw0rd'])
        ->assertStatus(401)->assertJsonPath('message', 'Two factor authentication is required.');

    m006As($token)->postJson('/api/v1/orgs/'.$org->id.'/transfer-ownership', [
        'to_user_id' => $bea->id, 'current_password' => 'Str0ng!Passw0rd', 'otp' => (new Google2FA)->getCurrentOtp($secret),
    ])->assertOk();
});
