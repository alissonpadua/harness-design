<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolesSeeder;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->seed(RolesSeeder::class);
});

function superAdmin(): User
{
    $user = User::factory()->create(['email' => 'root@example.com', 'password' => 'Str0ng!Passw0rd']);
    $user->assignRole('super-admin');

    return $user;
}

test('admin plane: 401 anonymous, 403 plain user, 200 super-admin', function () {
    $this->getJson('/admin/v1/ping')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');

    $member = User::factory()->create(['email' => 'ada@example.com', 'password' => 'Str0ng!Passw0rd']);
    $member->assignRole('user');

    $this->withToken($member->createToken('cli', ['*'])->plainTextToken)
        ->getJson('/admin/v1/ping')
        ->assertForbidden()
        ->assertJsonPath('message', 'This action is unauthorized.');

    $this->flushHeaders();
    $this->app['auth']->forgetGuards();

    $this->withToken(superAdmin()->createToken('cli', ['*'])->plainTextToken)
        ->getJson('/admin/v1/ping')
        ->assertOk()
        ->assertExactJson(['data' => ['scope' => 'admin', 'pong' => true]]);
});

test('permission catalog is well-formed resource.action names, seeded', function () {
    $catalog = config('permissions.catalog');

    expect($catalog)->not->toBeEmpty();
    foreach (array_keys($catalog) as $name) {
        expect((bool) preg_match('/^[a-z][a-z_]*\.[a-z][a-z_]*$/', $name))->toBeTrue("bad permission name: {$name}");
    }

    expect(Permission::query()->whereIn('name', array_keys($catalog))->count())
        ->toBe(count($catalog));
});

test('super-admin wildcard passes explicit permission checks; user role has nothing', function () {
    $admin = superAdmin();
    $member = User::factory()->create();
    $member->assignRole('user');

    expect($admin->checkPermissionTo('admin.access'))->toBeTrue()
        ->and($member->checkPermissionTo('admin.access'))->toBeFalse()
        ->and($member->getPermissionsViaRoles()->count())->toBe(0);
});

test('admin routes are documented in openapi like api routes', function () {
    $doc = json_decode((string) file_get_contents(base_path('openapi.json')), true, 512, JSON_THROW_ON_ERROR);

    $documented = collect($doc['paths'] ?? [])->keys()->map(fn (string $p) => ltrim($p, '/'))->all();

    expect($documented)->toContain('admin/v1/ping')
        ->toContain('api/v1/ping');
});
