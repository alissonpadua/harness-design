<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use App\Enums\OrgType;
use App\Events\Auth\UserRegistered;
use App\Events\Org\OrgCreated;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Tenancy;

function m002As(string $token)
{
    test()->flushHeaders();
    app('auth')->forgetGuards();

    return test()->withToken($token);
}

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    Notification::fake();
});

/* ───────────────────────── S1: bootstrap + protection ──────────────────── */

test('registration auto-creates personal workspace, sets current org, idempotent on replay', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Ada', 'email' => 'ada@example.com',
        'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
    ])->assertStatus(202);

    $user = User::firstWhere('email', 'ada@example.com');
    $personal = $user->currentOrganization;

    expect($personal)->not->toBeNull()
        ->and($personal->type)->toBe(OrgType::Personal)
        ->and($personal->name)->toBe("Ada's workspace")
        ->and($personal->membershipFor($user)->role->value)->toBe('owner');

    event(new UserRegistered($user));
    expect(Organization::query()->where('owner_id', $user->id)->count())->toBe(1);
});

test('personal workspace resists delete, leave and transfer', function () {
    [$ada, $token] = Tenancy::user();
    $personal = Tenancy::personalWorkspace($ada);

    m002As($token)->deleteJson('/api/v1/orgs/'.$personal->id, ['confirm_text' => $personal->name])
        ->assertForbidden()->assertJsonPath('message', 'Personal workspaces cannot be deleted.');
    m002As($token)->postJson('/api/v1/orgs/'.$personal->id.'/leave')
        ->assertForbidden()->assertJsonPath('message', 'Personal workspaces cannot be left.');
    m002As($token)->postJson('/api/v1/orgs/'.$personal->id.'/transfer-ownership', ['to_user_id' => 99, 'current_password' => 'Str0ng!Passw0rd'])
        ->assertForbidden()->assertJsonPath('message', 'Personal workspaces cannot be transferred.');
});

/* ────────────────────────── team CRUD + entitlement ─────────────────────── */

test('team creation: 201, owner membership, org.created event, slug set', function () {
    Event::fake([OrgCreated::class]);
    [$ada, $token] = Tenancy::user();
    Tenancy::personalWorkspace($ada);

    $response = m002As($token)->postJson('/api/v1/orgs', ['name' => 'Acme Rockets'])->assertCreated();

    $response->assertJsonPath('data.name', 'Acme Rockets')
        ->assertJsonPath('data.type', 'team')
        ->assertJsonPath('data.role', 'owner');

    Event::assertDispatched(OrgCreated::class);
    expect(Organization::firstWhere('name', 'Acme Rockets')->slug)->toBe('acme-rockets');
});

test('team entitlement ceiling → 402 Subscription required', function () {
    config(['tenancy.limits.max_teams' => 1]);
    [$ada, $token] = Tenancy::user();
    Tenancy::personalWorkspace($ada);

    m002As($token)->postJson('/api/v1/orgs', ['name' => 'One'])->assertCreated();
    m002As($token)->postJson('/api/v1/orgs', ['name' => 'Two'])
        ->assertStatus(402)->assertJsonPath('message', 'Subscription required.');
});

test('orgs list shows memberships with role and current flag', function () {
    [$ada, $token] = Tenancy::user();
    $personal = Tenancy::personalWorkspace($ada);
    $team = Tenancy::org($ada, 'Team X');

    $response = m002As($token)->getJson('/api/v1/orgs')->assertOk();

    $names = collect($response->json('data.orgs'))->pluck('name');
    $current = collect($response->json('data.orgs'))->firstWhere('name', $personal->name);

    expect($names)->toContain($personal->name, 'Team X')
        ->and($current['current'])->toBeTrue()
        ->and(collect($response->json('data.orgs'))->firstWhere('name', 'Team X')['role'])->toBe('owner');
});

test('show resolves by id and slug; non-member gets identical 404; suspended gets 403', function () {
    [$ada, $token] = Tenancy::user();
    $org = Tenancy::org($ada);

    m002As($token)->getJson('/api/v1/orgs/'.$org->id)->assertOk()->assertJsonPath('data.name', 'Acme');
    m002As($token)->getJson('/api/v1/orgs/'.$org->slug)->assertOk();

    [$bob, $bobToken] = Tenancy::user('bob@example.com');
    m002As($bobToken)->getJson('/api/v1/orgs/'.$org->id)->assertNotFound();
    m002As($bobToken)->getJson('/api/v1/orgs/does-not-exist')->assertNotFound();

    $org->memberships()->where('user_id', $ada->id)->update(['status' => 'suspended']);
    $this->flushHeaders();
    $this->app['auth']->forgetGuards();
    m002As($token)->getJson('/api/v1/orgs/'.$org->id)
        ->assertForbidden()->assertJsonPath('message', 'This action is unauthorized.');
});

test('settings update: owner ok, viewer/member forbidden, enum-restricted role field', function () {
    [$ada, $token] = Tenancy::user();
    $org = Tenancy::org($ada);
    [$v, $vToken] = Tenancy::user('v@example.com');
    Tenancy::addMember($org, $v, OrgRole::Viewer);

    m002As($token)->patchJson('/api/v1/orgs/'.$org->id, [
        'name' => 'Renamed', 'require_2fa' => true, 'default_member_role' => 'viewer',
    ])->assertOk()->assertJsonPath('data.require_2fa', true);

    m002As($vToken)->patchJson('/api/v1/orgs/'.$org->id, ['name' => 'Hijack'])
        ->assertForbidden()->assertJsonPath('message', 'This action is unauthorized.');

    m002As($token)->patchJson('/api/v1/orgs/'.$org->id, ['default_member_role' => 'owner'])
        ->assertStatus(422)->assertJsonValidationErrors('default_member_role');
});

test('deletion: confirm text gate, soft delete, 404 after, current pointers reset', function () {
    [$ada, $token] = Tenancy::user();
    Tenancy::personalWorkspace($ada);
    $team = Tenancy::org($ada, 'Doomed');
    m002As($token)->postJson('/api/v1/orgs/'.$team->id.'/switch')->assertOk();

    m002As($token)->deleteJson('/api/v1/orgs/'.$team->id, ['confirm_text' => 'wrong'])
        ->assertStatus(422)->assertJsonValidationErrors('confirm_text');

    m002As($token)->deleteJson('/api/v1/orgs/'.$team->id, ['confirm_text' => 'Doomed'])->assertOk();

    expect(Organization::withTrashed()->find($team->id)->deleted_at)->not->toBeNull()
        ->and($ada->refresh()->current_organization_id)->not->toBe($team->id);

    $this->flushHeaders();
    $this->app['auth']->forgetGuards();
    m002As($token)->getJson('/api/v1/orgs/'.$team->id)->assertNotFound();
});

/* ───────────────────────────── switching (S1) ──────────────────────────── */

test('switch requires active membership, flips current immediately', function () {
    [$ada, $token] = Tenancy::user();
    $personal = Tenancy::personalWorkspace($ada);
    $team = Tenancy::org($ada, 'Second');
    [$bob, $bobToken] = Tenancy::user('bob@example.com');
    Tenancy::personalWorkspace($bob);

    m002As($bobToken)->postJson('/api/v1/orgs/'.$team->id.'/switch')
        ->assertStatus(403)->assertJsonPath('message', 'You are not a member of this organization.');

    m002As($token)->postJson('/api/v1/orgs/'.$team->id.'/switch')
        ->assertOk()->assertJsonPath('data.current_organization_id', $team->id);
    expect($ada->refresh()->current_organization_id)->toBe($team->id);

    m002As($token)->postJson('/api/v1/orgs/'.$personal->id.'/switch')->assertOk();
    expect($ada->refresh()->current_organization_id)->toBe($personal->id);
});
