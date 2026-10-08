<?php

declare(strict_types=1);

namespace Tests\Feature\M005_AdminOps;

use App\Models\User;
use Database\Seeders\PlansSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Tenancy;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesSeeder::class);
});

function impAs(string $token)
{
    test()->flushHeaders();
    app('auth')->forgetGuards();

    return test()->withToken($token);
}

function adminPair(): array
{
    $root = User::factory()->create(['email' => 'root@i.test']);
    $root->assignRole('super-admin');

    return [$root, $root->createToken('cli', ['*'])->plainTextToken];
}

test('AC-005.3 impersonation lifecycle: start, use, list, stop, replaced', function () {
    [$root, $rt] = adminPair();
    [$ada] = Tenancy::user();

    DB::table('activity_log')->truncate();

    $start = impAs($rt)->postJson('/admin/v1/users/'.$ada->id.'/impersonate')->assertCreated()->json('data');
    $it = $start['token'];

    expect($start['target']['email'])->toBe('ada@example.com');

    // usable + /me transparency block (spec 005.3: BOTH /auth/me and /profile)
    $authMe = impAs($it)->getJson('/api/v1/auth/me')->assertOk()->json('data');
    expect($authMe['impersonation']['impersonator_id'])->toBe($root->id)
        ->and($authMe['impersonation']['impersonator_name'])->toBe($root->name);

    $me = impAs($it)->getJson('/api/v1/profile')->assertOk()->json('data');
    expect($me['email'])->toBe('ada@example.com')
        ->and($me['impersonation']['impersonator_id'])->toBe($root->id);

    // normal work is allowed…
    impAs($it)->getJson('/api/v1/orgs')->assertOk();

    // …and every allowed request is audited
    expect(DB::table('activity_log')->where('event', 'impersonated_request')->count())->toBeGreaterThanOrEqual(2)
        ->and((int) DB::table('activity_log')->where('event', 'impersonated_request')->latest('id')->value('causer_id'))->toBe($ada->id);

    // list live
    impAs($rt)->getJson('/admin/v1/impersonations')->assertOk()
        ->assertJsonPath('data.impersonations.0.target_id', $ada->id);

    // stop -> dead immediately; stop unknown -> 404
    $tid = $start['token_id'];
    impAs($rt)->deleteJson('/admin/v1/impersonations/'.$tid)->assertOk();
    impAs($it)->getJson('/api/v1/profile')->assertUnauthorized();
    impAs($rt)->deleteJson('/admin/v1/impersonations/'.$tid)->assertNotFound();

    expect(DB::table('activity_log')->where('event', 'impersonation_start')->exists())->toBeTrue()
        ->and(DB::table('activity_log')->where('event', 'impersonation_stop')->exists())->toBeTrue();

    // restart replaces prior token
    $a = impAs($rt)->postJson('/admin/v1/users/'.$ada->id.'/impersonate')->assertCreated()->json('data');
    $b = impAs($rt)->postJson('/admin/v1/users/'.$ada->id.'/impersonate')->assertCreated()->json('data');
    impAs($a['token'])->getJson('/api/v1/profile')->assertUnauthorized();
    impAs($b['token'])->getJson('/api/v1/profile')->assertOk();
});

test('AC-005.3 exclusions: impersonated sessions are barred from admin, security, destroy, transfer', function () {
    [$root, $rt] = adminPair();
    [$ada, $adaToken] = Tenancy::user();
    $org = Tenancy::org($ada, 'ImpCo');

    $it = impAs($rt)->postJson('/admin/v1/users/'.$ada->id.'/impersonate')->json('data.token');
    $msg = 'Impersonated sessions cannot perform this action.';

    impAs($it)->getJson('/admin/v1/ping')->assertForbidden()->assertJsonPath('message', $msg);
    impAs($it)->postJson('/api/v1/auth/2fa/enroll')->assertForbidden();
    impAs($it)->putJson('/api/v1/profile/password', ['current_password' => 'password', 'password' => 'Fake!Passw0rd2', 'password_confirmation' => 'Fake!Passw0rd2'])->assertForbidden();
    impAs($it)->putJson('/api/v1/profile/email', ['email' => 'sneaky@x.test', 'password' => 'Str0ng!Passw0rd'])->assertForbidden();
    impAs($it)->postJson('/api/v1/profile/delete-account', ['password' => 'Str0ng!Passw0rd'])->assertForbidden();
    impAs($it)->deleteJson('/api/v1/orgs/'.$org->id, ['confirm_text' => 'ImpCo'])->assertForbidden();
    impAs($it)->postJson('/api/v1/orgs/'.$org->id.'/transfer-ownership', ['to_user_id' => $root->id, 'current_password' => 'Str0ng!Passw0rd'])->assertForbidden();

    // safe surfaces still work
    $this->seed(PlansSeeder::class);
    impAs($it)->getJson('/api/v1/orgs/'.$org->id)->assertOk();
    impAs($it)->getJson('/api/v1/notifications/preferences')->assertOk();
    impAs($it)->postJson("/api/v1/orgs/{$org->id}/billing/subscription/change", ['plan_code' => 'business'])->assertOk();

    // self-impersonation rejected
    impAs($rt)->postJson('/admin/v1/users/'.$root->id.'/impersonate')->assertStatus(422);
    // suspended target rejected
    $late = User::factory()->create(['email' => 'late@i.test']);
    impAs($rt)->postJson('/admin/v1/users/'.$late->id.'/suspend', ['reason' => 'freeze now'])->assertOk();
    impAs($rt)->postJson('/admin/v1/users/'.$late->id.'/impersonate')->assertStatus(422);
    unset($adaToken);
});

test('impersonated tokens never expire on their own and survive normal device churn', function () {
    [$root, $rt] = adminPair();
    [$ada] = Tenancy::user();

    $it = impAs($rt)->postJson('/admin/v1/users/'.$ada->id.'/impersonate')->json('data.token');

    // victim does a regular web login (same-type replacement must NOT nuke the null-device impersonation token)
    $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'Str0ng!Passw0rd', 'device_type' => 'web'])->assertOk();

    impAs($it)->getJson('/api/v1/profile')->assertOk();
});
