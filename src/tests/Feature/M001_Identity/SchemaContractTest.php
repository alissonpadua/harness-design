<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Models\Role;

function columnsOf(string $table): array
{
    return Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
}

function assertHasColumns(string $table, array $required): void
{
    expect(array_values(array_diff($required, columnsOf($table))))
        ->toBe([], "columns missing on {$table}");
}

test('users table carries identity lifecycle columns', function () {
    assertHasColumns('users', [
        'deleted_at',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'locale',
        'timezone',
        'current_organization_id',
    ]);
});

test('personal_access_tokens supports device binding and usage tracking', function () {
    assertHasColumns('personal_access_tokens', [
        'device_type', 'name', 'ip_address', 'user_agent', 'last_used_at', 'expires_at',
    ]);
});

test('identity support tables exist', function () {
    foreach (['oauth_accounts', 'auth_links', 'webauthn_keys', 'roles', 'permissions'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing table: {$table}");
    }
});

test('oauth_accounts enforces provider identity uniqueness', function () {
    $indexes = collect(Schema::getIndexes('oauth_accounts'))
        ->map(fn (array $i) => array_merge([$i['name']], $i['columns']));

    expect($indexes->contains(fn (array $i) => $i === ['oauth_accounts_provider_provider_id_unique', 'provider', 'provider_id']))
        ->toBeTrue();
});

test('auth_links is single-use and typed', function () {
    assertHasColumns('auth_links', ['type', 'user_id', 'expires_at', 'used_at']);
});

test('User model wires soft deletes, api tokens and encrypted casts', function () {
    $traits = class_uses_recursive(User::class);

    expect($traits)->toHaveKeys([
        SoftDeletes::class,
        HasApiTokens::class,
    ]);

    $user = new User([
        'password' => 'SomeComplex1!pass',
    ]);
    $user->two_factor_secret = 'plain-secret';
    $user->two_factor_recovery_codes = ['one', 'two'];

    expect($user->getCasts())
        ->toHaveKeys(['two_factor_secret', 'two_factor_recovery_codes', 'email_verified_at'])
        ->and($user->getCasts()['password'])->toBe('hashed')
        ->and($user->getHidden())->toContain('two_factor_secret', 'two_factor_recovery_codes')
        ->and($user->two_factor_recovery_codes)->toBe(['one', 'two'])
        ->and($user->getAttributes()['two_factor_secret'])->not->toBe('plain-secret');
});

test('password hashing uses argon2id and complexity rules are centralized', function () {
    expect(config('hashing.driver'))->toBe('argon2id');

    $rules = config('auth.password.rules');

    expect($rules)->toBeArray()
        ->and($rules)->toContain('min:10', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/[0-9]/', 'regex:/[^a-zA-Z0-9]/');
});

test('seeder registers the global role plane', function () {
    $this->seed(RolesSeeder::class);

    expect(Role::pluck('name'))->toContain('super-admin', 'user')
        ->and(Role::findByName('super-admin')->hasPermissionTo('*'))->toBeTrue();
});

test('sanctum guard is configured for api plane', function () {
    expect(config('auth.guards.sanctum.driver'))->toBe('sanctum');
});
