<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use App\Models\OrganizationInvite;
use App\Models\OrganizationInviteLink;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Tenancy;

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    Notification::fake();
});

function m007As(string $token)
{
    test()->flushHeaders();
    app('auth')->forgetGuards();

    return test()->withToken($token);
}

/**
 * S2 / AC-002.16: user A (org Alpha) probes every surface of org Beta she
 * does not belong to — all must be indistinguishable from "does not exist".
 */
test('cross-tenant matrix: everything an outsider probes answers 404 (switch: 403)', function () {
    [$ada, $adaToken] = Tenancy::user();          // owner of Alpha
    $alpha = Tenancy::org($ada, 'Alpha');
    [$bob] = Tenancy::user('bob@example.com');  // owner of Beta
    $beta = Tenancy::org($bob, 'Beta');
    $carol = User::factory()->create(['email' => 'carol@example.com']);
    Tenancy::addMember($beta, $carol, OrgRole::Member);

    // pending invite + live link inside Beta
    $invite = OrganizationInvite::makeWithToken();
    $invite->forceFill(['organization_id' => $beta->id, 'email' => 'victim@example.com', 'role' => 'member', 'invited_by' => $bob->id, 'expires_at' => now()->addDay()])->save();
    $link = OrganizationInviteLink::makeWithToken();
    $link->forceFill(['organization_id' => $beta->id, 'role' => 'member', 'created_by' => $bob->id, 'expires_at' => now()->addDay()])->save();

    $probes = [
        ['GET', '/api/v1/orgs/'.$beta->id],
        ['GET', '/api/v1/orgs/beta'],
        ['PATCH', '/api/v1/orgs/'.$beta->id],
        ['DELETE', '/api/v1/orgs/'.$beta->id],
        ['GET', '/api/v1/orgs/'.$beta->id.'/members'],
        ['PATCH', '/api/v1/orgs/'.$beta->id.'/members/'.$carol->id],
        ['POST', '/api/v1/orgs/'.$beta->id.'/members/'.$carol->id.'/suspend'],
        ['DELETE', '/api/v1/orgs/'.$beta->id.'/members/'.$carol->id],
        ['GET', '/api/v1/orgs/'.$beta->id.'/invites'],
        ['POST', '/api/v1/orgs/'.$beta->id.'/invites'],
        ['DELETE', '/api/v1/orgs/'.$beta->id.'/invites/'.$invite->id],
        ['GET', '/api/v1/orgs/'.$beta->id.'/invite-links'],
        ['POST', '/api/v1/orgs/'.$beta->id.'/invite-links'],
        ['DELETE', '/api/v1/orgs/'.$beta->id.'/invite-links/'.$link->id],
        ['POST', '/api/v1/orgs/'.$beta->id.'/transfer-ownership'],
    ];

    foreach ($probes as [$method, $uri]) {
        $response = m007As($adaToken)->json($method, $uri, ['confirm_text' => 'x', 'role' => 'member', 'email' => 'x@y.zz', 'name' => 'Nope', 'to_user_id' => 1, 'current_password' => 'Str0ng!Passw0rd']);
        $response->assertNotFound("expected 404 for {$method} {$uri}");
    }

    // switch is the one surface that discloses non-membership (locked decision)
    m007As($adaToken)->postJson('/api/v1/orgs/'.$beta->id.'/switch')
        ->assertForbidden()->assertJsonPath('message', 'You are not a member of this organization.');

    // cross-org pivot idor under HER OWN org: carol id with alpha org context → 404
    m007As($adaToken)->deleteJson('/api/v1/orgs/'.$alpha->id.'/members/'.$carol->id)->assertNotFound();
});

test('token capabilities respect their own rules cross-tenant (accept mismatch 403, link join is by design)', function () {
    [$ada, $adaToken] = Tenancy::user();
    $org = Tenancy::org($ada);

    // Ada cannot consume an invite written for someone else
    $invite = OrganizationInvite::makeWithToken();
    $invite->forceFill(['organization_id' => $org->id, 'email' => 'other@example.com', 'role' => 'member', 'invited_by' => $ada->id, 'expires_at' => now()->addDay()])->save();
    m007As($adaToken)->postJson('/api/v1/invites/accept', ['token' => $invite->token()])->assertForbidden();

    // shareable links admit anyone on purpose (capability model)
    $link = OrganizationInviteLink::makeWithToken();
    $link->forceFill(['organization_id' => $org->id, 'role' => 'viewer', 'created_by' => $ada->id, 'expires_at' => now()->addDay()])->save();
    [$zed, $zedToken] = Tenancy::user('zed@example.com');
    m007As($zedToken)->postJson('/api/v1/invite-links/join', ['token' => $link->token()])
        ->assertOk()->assertJsonPath('data.organization_id', $org->id);
});

test('AC-002.17: switching org instantly re-contextualizes scoped reads', function () {
    [$ada, $adaToken] = Tenancy::user();
    $alpha = Tenancy::org($ada, 'Alpha');
    Tenancy::personalWorkspace($ada);
    $beta = Tenancy::org($ada, 'Beta');
    Tenancy::switchTo($ada, $alpha);

    $inviteA = OrganizationInvite::makeWithToken();
    $inviteA->forceFill(['organization_id' => $alpha->id, 'email' => 'a@a.aa', 'role' => 'member', 'invited_by' => $ada->id, 'expires_at' => now()->addDay()])->save();
    $inviteB = OrganizationInvite::makeWithToken();
    $inviteB->forceFill(['organization_id' => $beta->id, 'email' => 'b@b.bb', 'role' => 'member', 'invited_by' => $ada->id, 'expires_at' => now()->addDay()])->save();

    // endpoint-level: both visible with membership; scope-level handled in schema test.
    m007As($adaToken)->getJson('/api/v1/orgs/'.$alpha->id.'/invites')->assertOk()->assertJsonPath('data.invites.0.email', 'a@a.aa');
    m007As($adaToken)->getJson('/api/v1/orgs/'.$beta->id.'/invites')->assertOk()->assertJsonPath('data.invites.0.email', 'b@b.bb');

    // switching flips current immediately
    m007As($adaToken)->postJson('/api/v1/orgs/'.$beta->id.'/switch')->assertOk();
    expect($ada->refresh()->current_organization_id)->toBe($beta->id);
});

test('deleted org vanishes for members too (soft-delete 404 everywhere)', function () {
    [$ada, $adaToken] = Tenancy::user();
    Tenancy::personalWorkspace($ada);
    $team = Tenancy::org($ada, 'Gone');

    m007As($adaToken)->deleteJson('/api/v1/orgs/'.$team->id, ['confirm_text' => 'Gone'])->assertOk();
    m007As($adaToken)->getJson('/api/v1/orgs/'.$team->id)->assertNotFound();
    m007As($adaToken)->getJson('/api/v1/orgs/'.$team->id.'/members')->assertNotFound();
    expect(collect(m007As($adaToken)->getJson('/api/v1/orgs')->json('data.orgs'))->pluck('name'))->not->toContain('Gone');
});
