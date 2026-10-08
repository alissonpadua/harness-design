<?php

declare(strict_types=1);

namespace Tests\Feature\M005_AdminOps;

use App\Actions\Org\DeleteOrganizationAction;
use App\Models\AuthLink;
use App\Models\OauthAccount;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Tenancy;

uses(RefreshDatabase::class);

function admin(): array
{
    $root = User::factory()->create(['email' => 'root@a.test']);
    $root->assignRole('super-admin');

    return [$root, $root->createToken('cli', ['*'])->plainTextToken];
}

function adminAs(string $token)
{
    test()->flushHeaders();
    app('auth')->forgetGuards();

    return test()->withToken($token);
}

beforeEach(function () {
    $this->seed(RolesSeeder::class);
});

test('AC-005.1 non-super-admin rejected on every user route', function () {
    [$victim] = Tenancy::user();
    $plain = User::factory()->create(['email' => 'plain@a.test']);
    $pt = $plain->createToken('cli', ['*'])->plainTextToken;

    foreach ([
        ['get', '/admin/v1/users'],
        ['get', '/admin/v1/users/'.$victim->id],
        ['post', '/admin/v1/users/'.$victim->id.'/suspend'],
        ['post', '/admin/v1/users/'.$victim->id.'/unsuspend'],
        ['post', '/admin/v1/users/'.$victim->id.'/restore'],
        ['delete', '/admin/v1/users/'.$victim->id],
    ] as [$verb, $uri]) {
        adminAs($pt)->{$verb.'Json'}($uri)->assertForbidden();
    }
});

test('AC-005.2 suspend: reason mandatory, tokens revoked, login blocked, audit written', function () {
    [$root, $rt] = admin();
    [$ada, $adaToken] = Tenancy::user();

    adminAs($rt)->postJson('/admin/v1/users/'.$ada->id.'/suspend', [])->assertStatus(422)->assertJsonValidationErrors('reason');
    adminAs($rt)->postJson('/admin/v1/users/'.$ada->id.'/suspend', ['reason' => 'ab'])->assertStatus(422);

    adminAs($rt)->postJson('/admin/v1/users/'.$ada->id.'/suspend', ['reason' => 'payment fraud investigation'])
        ->assertOk()->assertJsonPath('data.suspended_at', fn ($v) => is_string($v));

    expect($ada->refresh()->isSuspended())->toBeTrue()
        ->and($ada->tokens()->count())->toBe(0);

    // old bearer was revoked with the suspension -> 401; login gets the dedicated 403
    adminAs($adaToken)->getJson('/api/v1/profile')->assertUnauthorized();
    $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'Str0ng!Passw0rd', 'device_type' => 'web'])
        ->assertStatus(403)->assertJsonPath('message', 'Account suspended.');

    // double suspend rejected; audit present
    adminAs($rt)->postJson('/admin/v1/users/'.$ada->id.'/suspend', ['reason' => 'again reason'])
        ->assertStatus(422)->assertJsonValidationErrors('user');
    expect(DB::table('activity_log')->where('event', 'user_suspend')->where('causer_id', $root->id)->exists())->toBeTrue();
});

test('AC-005.2/12 suspend-then-unsuspend restores login; fresh token of suspended user blocked mid-session', function () {
    [, $rt] = admin();
    [$ada] = Tenancy::user();

    adminAs($rt)->postJson('/admin/v1/users/'.$ada->id.'/suspend', ['reason' => 'temporary hold'])->assertOk();
    $late = $ada->createToken('cli', ['*'])->plainTextToken; // direct mint edge (bypass) still gated
    adminAs($late)->getJson('/api/v1/profile')->assertForbidden();

    adminAs($rt)->postJson('/admin/v1/users/'.$ada->id.'/unsuspend')->assertOk();
    adminAs($rt)->postJson('/admin/v1/users/'.$ada->id.'/unsuspend')->assertStatus(422);

    $t2 = $ada->refresh()->createToken('cli', ['*'])->plainTextToken;
    adminAs($t2)->getJson('/api/v1/profile')->assertOk();
});

test('AC-005.4 restore: soft-deleted user back; suspension survives restore', function () {
    [$root, $rt] = admin();
    [$ada] = Tenancy::user();

    $ada->delete();
    adminAs($rt)->postJson('/admin/v1/users/'.$ada->id.'/restore')->assertOk();
    expect($ada->refresh()->trashed())->toBeFalse();

    adminAs($rt)->postJson('/admin/v1/users/'.$ada->id.'/restore')->assertStatus(422);

    // suspended + deleted -> restore keeps suspension
    adminAs($rt)->postJson('/admin/v1/users/'.$ada->id.'/suspend', ['reason' => 'pre delete hold'])->assertOk();
    $ada->delete();
    adminAs($rt)->postJson('/admin/v1/users/'.$ada->id.'/restore')->assertOk();
    expect($ada->refresh()->isSuspended())->toBeTrue();
    unset($root);
});

test('AC-005.11 force-delete: confirm gate, owned-team block, hard cascade, audit survives', function () {
    [$root, $rt] = admin();
    [$ada] = Tenancy::user();
    $org = Tenancy::org($ada, 'OwnedCo');
    OauthAccount::create(['user_id' => $ada->id, 'provider' => 'google', 'provider_id' => 'g1', 'provider_email' => 'a@g']);
    AuthLink::issue($ada, 'magic_link');
    $ada->createToken('cli', ['*']);

    adminAs($rt)->deleteJson('/admin/v1/users/'.$ada->id, ['confirm_text' => 'WRONG@X.TEST'])->assertStatus(422)->assertJsonValidationErrors('confirm_text');
    adminAs($rt)->deleteJson('/admin/v1/users/'.$ada->id, ['confirm_text' => 'ada@example.com'])
        ->assertStatus(422)->assertJsonPath('errors.user.0', 'Transfer or delete owned organizations first (1).');

    // owned teams: soft-delete alone still blocks the FK…
    app(DeleteOrganizationAction::class)->handle($org, 'OwnedCo');
    adminAs($rt)->deleteJson('/admin/v1/users/'.$ada->id, ['confirm_text' => 'ada@example.com'])
        ->assertStatus(422)->assertJsonPath('errors.user.0', 'Transfer or delete owned organizations first (1).');
    // …until purged (ops reality documented in spec Q3)
    $org->forceDelete();

    adminAs($rt)->deleteJson('/admin/v1/users/'.$ada->id, ['confirm_text' => 'ada@example.com'])->assertOk();

    expect(User::withTrashed()->find($ada->id))->toBeNull()
        ->and(\DB::table('personal_access_tokens')->where('tokenable_id', $ada->id)->count())->toBe(0)
        ->and(OauthAccount::count())->toBe(0)
        ->and(AuthLink::query()->where('user_id', $ada->id)->count())->toBe(0)
        ->and(DB::table('activity_log')->where('event', 'user_force_delete')->where('subject_id', $ada->id)->exists())->toBeTrue();
});

test('AC-005.14 admin.login audited for super-admins only', function () {
    [$root] = admin();
    DB::table('activity_log')->truncate();

    $this->postJson('/api/v1/auth/login', ['email' => 'root@a.test', 'password' => 'password', 'device_type' => 'web']);
    expect(DB::table('activity_log')->where('event', 'admin_login')->count())->toBe(1);

    [$ada] = Tenancy::user();
    DB::table('activity_log')->truncate();
    $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'Str0ng!Passw0rd', 'device_type' => 'web'])
        ->assertOk();
    expect(DB::table('activity_log')->where('event', 'admin_login')->count())->toBe(0);
});

test('AC-005.16 users list: search + status filters', function () {
    [, $rt] = admin();
    [$ada] = Tenancy::user('findme@example.com');
    [$bob] = Tenancy::user('other@example.com');
    adminAs($rt)->postJson('/admin/v1/users/'.$bob->id.'/suspend', ['reason' => 'spam wave']);

    adminAs($rt)->getJson('/admin/v1/users?q=findme')->assertOk()->assertJsonCount(1, 'data.users');
    adminAs($rt)->getJson('/admin/v1/users?status=suspended')->assertOk()->assertJsonPath('data.users.0.email', 'other@example.com');
    $all = adminAs($rt)->getJson('/admin/v1/users?status=all')->assertOk();
    expect($all->json('data.users'))->toHaveCount(3);
    unset($ada);
});
