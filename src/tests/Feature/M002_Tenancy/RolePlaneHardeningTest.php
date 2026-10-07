<?php

declare(strict_types=1);

namespace Tests\Feature\M002_Tenancy;

use App\Enums\OrgRole;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Tenancy;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesSeeder::class);
});

function hardeningAs(string $token)
{
    test()->flushHeaders();
    app('auth')->forgetGuards();

    return test()->withToken($token);
}

/* audit run-1 T0 regressions (specs/active/005-admin-ops T0) */

test('T0-1 nobody can grant or receive the owner role via update_role', function () {
    [$ada, $adaToken] = Tenancy::user();
    $org = Tenancy::org($ada, 'Escalate');
    [$eve, $eveToken] = Tenancy::user('eve@x.test');
    Tenancy::addMember($org, $eve, OrgRole::Admin);

    // admin self-promotes to owner -> 422 (validation ban)
    hardeningAs($eveToken)->patchJson('/api/v1/orgs/'.$org->id.'/members/'.$eve->id, ['role' => 'owner'])
        ->assertStatus(422)->assertJsonValidationErrors('role');

    // owner trying to hand ownership out (bypassing transfer) -> 422
    hardeningAs($adaToken)->patchJson('/api/v1/orgs/'.$org->id.'/members/'.$eve->id, ['role' => 'owner'])
        ->assertStatus(422)->assertJsonValidationErrors('role');

    expect($eve->refresh()->memberships()->where('organization_id', $org->id)->first()->role)->toBe(OrgRole::Admin);
});

test('T0-2 owners cannot be demoted via update_role (sole keeps 409 contract, multi blocked 422)', function () {
    [$ada, $adaToken] = Tenancy::user();
    $org = Tenancy::org($ada, 'TwoOwner');
    [$ben, $benToken] = Tenancy::user('ben@x.test');
    Tenancy::addMember($org, $ben, OrgRole::Owner); // simulate second owner row

    // sole-owner demotion contract unchanged elsewhere; multi-owner demotion now blocked too
    hardeningAs($adaToken)->patchJson('/api/v1/orgs/'.$org->id.'/members/'.$ben->id, ['role' => 'admin'])
        ->assertStatus(422)->assertJsonPath('errors.role.0', 'Ownership changes require the transfer-ownership flow.');

    // demoting the (now non-sole) original owner is likewise blocked
    hardeningAs($adaToken)->patchJson('/api/v1/orgs/'.$org->id.'/members/'.$ada->id, ['role' => 'member'])
        ->assertStatus(422);
});

test('T0-3 owners cannot be suspended nor removed (suspend->evict chain closed)', function () {
    [$ada, $adaToken] = Tenancy::user();
    $org = Tenancy::org($ada, 'Decapitate');
    [$sam, $samToken] = Tenancy::user('sam@x.test');
    Tenancy::addMember($org, $sam, OrgRole::Admin);

    hardeningAs($samToken)->postJson('/api/v1/orgs/'.$org->id.'/members/'.$ada->id.'/suspend')
        ->assertForbidden()->assertJsonPath('message', 'Owners cannot be suspended.');

    hardeningAs($samToken)->deleteJson('/api/v1/orgs/'.$org->id.'/members/'.$ada->id)
        ->assertStatus(422)->assertJsonValidationErrors('user');

    expect($org->memberships()->where('role', 'owner')->count())->toBe(1);
});

test('T0-4 normal member role management still works (no over-block)', function () {
    [$ada, $adaToken] = Tenancy::user();
    $org = Tenancy::org($ada, 'StillWorks');
    [$kim, $kimToken] = Tenancy::user('kim@x.test');
    Tenancy::addMember($org, $kim);

    hardeningAs($adaToken)->patchJson('/api/v1/orgs/'.$org->id.'/members/'.$kim->id, ['role' => 'admin'])->assertOk();
    hardeningAs($adaToken)->patchJson('/api/v1/orgs/'.$org->id.'/members/'.$kim->id, ['role' => 'viewer'])->assertOk();
    hardeningAs($adaToken)->postJson('/api/v1/orgs/'.$org->id.'/members/'.$kim->id.'/suspend')->assertOk();
    hardeningAs($adaToken)->postJson('/api/v1/orgs/'.$org->id.'/members/'.$kim->id.'/unsuspend')->assertOk();
    hardeningAs($adaToken)->deleteJson('/api/v1/orgs/'.$org->id.'/members/'.$kim->id)->assertOk();
    unset($kimToken);
});
