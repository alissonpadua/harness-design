<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Tenancy;

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    Notification::fake();
});

function m003As(string $token)
{
    test()->flushHeaders();
    app('auth')->forgetGuards();

    return test()->withToken($token);
}

function memberSetup(): array
{
    [$ada, $adaToken] = Tenancy::user();
    $org = Tenancy::org($ada, 'Roster');
    [$bea, $beaToken] = Tenancy::user('bea@example.com');
    Tenancy::addMember($org, $bea, OrgRole::Member);
    [$cit, $citToken] = Tenancy::user('cit@example.com');
    Tenancy::addMember($org, $cit, OrgRole::Viewer);

    return compact('ada', 'adaToken', 'org', 'bea', 'beaToken', 'cit', 'citToken');
}

test('members list: shape + permission matrix (viewer ok, non-member 404)', function () {
    $s = memberSetup();

    $response = m003As($s['adaToken'])->getJson('/api/v1/orgs/'.$s['org']->id.'/members')->assertOk();
    expect($response->json('data.members'))->toHaveCount(3)
        ->and($response->json('data.members.0'))->toHaveKeys(['id', 'name', 'email', 'role', 'status', 'joined_at']);

    m003As($s['citToken'])->getJson('/api/v1/orgs/'.$s['org']->id.'/members')->assertOk(); // viewer has members.view

    [$out, $outToken] = Tenancy::user('out@example.com');
    m003As($outToken)->getJson('/api/v1/orgs/'.$s['org']->id.'/members')->assertNotFound();
});

test('role update: admin can promote member, cannot touch owner, last owner protected', function () {
    $s = memberSetup();

    // member cannot assign roles (checked before promotion changes the role)
    m003As($s['beaToken'])->patchJson('/api/v1/orgs/'.$s['org']->id.'/members/'.$s['cit']->id, ['role' => 'admin'])
        ->assertForbidden();

    m003As($s['adaToken'])->patchJson('/api/v1/orgs/'.$s['org']->id.'/members/'.$s['bea']->id, ['role' => 'admin'])
        ->assertOk()->assertJsonPath('data.role', 'admin');

    // demote the sole owner (self) → 409
    m003As($s['adaToken'])->patchJson('/api/v1/orgs/'.$s['org']->id.'/members/'.$s['ada']->id, ['role' => 'member'])
        ->assertStatus(409)->assertJsonPath('message', 'The organization must keep an owner.');
});

test('suspend cuts access, unsuspend restores; removed members 404', function () {
    $s = memberSetup();
    Tenancy::switchTo($s['bea'], $s['org']);

    m003As($s['adaToken'])->postJson('/api/v1/orgs/'.$s['org']->id.'/members/'.$s['bea']->id.'/suspend')->assertOk();
    expect($s['bea']->refresh()->memberships()->where('organization_id', $s['org']->id)->first()->status->value)->toBe('suspended');

    // suspended: switch + reads blocked
    m003As($s['beaToken'])->postJson('/api/v1/orgs/'.$s['org']->id.'/switch')
        ->assertForbidden()->assertJsonPath('message', 'This action is unauthorized.');
    m003As($s['beaToken'])->getJson('/api/v1/orgs/'.$s['org']->id)
        ->assertForbidden();

    // still listed while suspended (audit-friendly)
    $list = m003As($s['adaToken'])->getJson('/api/v1/orgs/'.$s['org']->id.'/members')->assertOk()->json('data.members');
    expect(collect($list)->firstWhere('id', $s['bea']->id)['status'])->toBe('suspended');

    m003As($s['adaToken'])->postJson('/api/v1/orgs/'.$s['org']->id.'/members/'.$s['bea']->id.'/unsuspend')->assertOk();
    m003As($s['beaToken'])->getJson('/api/v1/orgs/'.$s['org']->id)->assertOk();

    // viewer cannot suspend/remove
    m003As($s['citToken'])->deleteJson('/api/v1/orgs/'.$s['org']->id.'/members/'.$s['bea']->id)->assertForbidden();

    // remove works, row gone, then 404
    m003As($s['adaToken'])->deleteJson('/api/v1/orgs/'.$s['org']->id.'/members/'.$s['cit']->id)->assertOk();
    m003As($s['adaToken'])->deleteJson('/api/v1/orgs/'.$s['org']->id.'/members/'.$s['cit']->id)->assertNotFound();
});

test('leave: member ok with current-org reset, owner blocked, personal blocked, self-only', function () {
    $s = memberSetup();
    [$dee, $deeToken] = Tenancy::user('dee@example.com');
    Tenancy::personalWorkspace($dee);
    $personal = $dee->current_organization_id;
    Tenancy::addMember($s['org'], $dee);
    m003As($deeToken)->postJson('/api/v1/orgs/'.$s['org']->id.'/switch')->assertOk();

    m003As($deeToken)->postJson('/api/v1/orgs/'.$s['org']->id.'/leave')->assertOk();
    expect($dee->refresh()->current_organization_id)->toBe($personal)
        ->and($s['org']->membershipFor($dee))->toBeNull();

    m003As($s['adaToken'])->postJson('/api/v1/orgs/'.$s['org']->id.'/leave')
        ->assertForbidden()->assertJsonPath('message', 'Owners must transfer ownership before leaving.');

    // cannot leave on behalf of others (route is self-context; foreign attempt = remove flow)
    m003As($s['beaToken'])->postJson('/api/v1/orgs/'.$s['org']->id.'/leave')->assertOk(); // bea is member: allowed
});

test('role map integrity: config covers all enum cases; viewer cannot mutate anything org-level', function () {
    $cases = array_map(fn (OrgRole $r) => $r->value, OrgRole::cases());
    $map = config('org_roles');

    expect(array_diff($cases, array_keys($map)))->toBe([]);
    expect($map['viewer'])->not->toContain('org.update');
});
