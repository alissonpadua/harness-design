<?php

declare(strict_types=1);

namespace Tests\Feature\M006_Security;

use App\Models\OauthAccount;
use App\Models\User;
use App\Settings\RegistrationsSettings;
use Database\Seeders\PlansSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\Facades\Socialite;
use Tests\Support\FakeSocialite;
use Tests\Support\FakeSocialiteUser;
use Tests\Support\Tenancy;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    $this->seed(PlansSeeder::class);
});

function killAdmin(): array
{
    $root = User::factory()->create(['email' => 'root@kill.test']);
    $root->assignRole('super-admin');

    return [$root, $root->createToken('cli', ['*'])->plainTextToken];
}

function killAs(string $token)
{
    test()->flushHeaders();
    app('auth')->forgetGuards();

    return test()->withToken($token);
}

test('AC-006.10 registrations open by default', function () {
    expect(app(RegistrationsSettings::class)->open)->toBeTrue();

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Fresh', 'email' => 'fresh@kill.test',
        'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
    ])->assertSuccessful();
});

test('AC-006.10 admin toggles the kill-switch and register answers 403', function () {
    [, $rt] = killAdmin();

    killAs($rt)->putJson('/admin/v1/settings/registrations', ['open' => false])
        ->assertOk()->assertJsonPath('data.open', false);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Blocked', 'email' => 'blocked@kill.test',
        'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
    ])->assertStatus(403)->assertJsonPath('message', 'Registrations are closed.');

    expect(User::query()->where('email', 'blocked@kill.test')->exists())->toBeFalse();

    // audit trail captured the change
    expect((bool) \DB::table('activity_log')->where('event', 'settings_change')->exists())->toBeTrue();

    // reopen
    killAs($rt)->putJson('/admin/v1/settings/registrations', ['open' => true])->assertOk();
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Late', 'email' => 'late@kill.test',
        'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
    ])->assertSuccessful();
});

test('AC-006.10 existing users keep logging in while closed', function () {
    [, $rt] = killAdmin();
    [$ada] = Tenancy::user();

    killAs($rt)->putJson('/admin/v1/settings/registrations', ['open' => false])->assertOk();

    $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'Str0ng!Passw0rd', 'device_type' => 'web'])
        ->assertSuccessful();
});

test('AC-006.10 oauth: existing identity unaffected, NEW-user creation blocked when closed', function () {
    [, $rt] = killAdmin();
    $existing = User::factory()->create(['email' => 'oa@kill.test', 'email_verified_at' => now()]);
    OauthAccount::create(['user_id' => $existing->id, 'provider' => 'google', 'provider_id' => 'g-keep', 'provider_email' => 'oa@kill.test']);

    $mock = function (string $id, string $email) {
        Socialite::clearResolvedInstances();
        app()->instance(
            Factory::class,
            new FakeSocialite(new FakeSocialiteUser($id, $email))
        );
    };
    $mock('g-keep', 'oa@kill.test');
    $this->postJson('/api/v1/auth/oauth/google/exchange', ['code' => 'c', 'device_type' => 'web'])->assertSuccessful();

    killAs($rt)->putJson('/admin/v1/settings/registrations', ['open' => false])->assertOk();

    // existing user still fine
    $mock('g-keep', 'oa@kill.test');
    $this->postJson('/api/v1/auth/oauth/google/exchange', ['code' => 'c', 'device_type' => 'web'])->assertSuccessful();

    // brand-new google identity -> 403 closed
    $mock('g-new', 'never@kill.test');
    $this->postJson('/api/v1/auth/oauth/google/exchange', ['code' => 'c', 'device_type' => 'web'])
        ->assertStatus(403)->assertJsonPath('message', 'Registrations are closed.');
    expect(User::query()->where('email', 'never@kill.test')->exists())->toBeFalse();
});

test('AC-006.10 admin-only toggle + shape', function () {
    [, $plainToken] = Tenancy::user();

    killAs($plainToken)->getJson('/admin/v1/settings/registrations')->assertForbidden();
    killAs($plainToken)->putJson('/admin/v1/settings/registrations', ['open' => false])->assertForbidden();

    [, $rt] = killAdmin();
    killAs($rt)->getJson('/admin/v1/settings/registrations')->assertOk()->assertJsonPath('data.open', true);

    // validation: open must be boolean
    killAs($rt)->putJson('/admin/v1/settings/registrations', ['open' => 'nope'])->assertStatus(422);
});
