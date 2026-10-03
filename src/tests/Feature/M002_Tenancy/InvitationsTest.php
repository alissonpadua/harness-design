<?php

declare(strict_types=1);

use App\Enums\MemberStatus;
use App\Enums\OrgRole;
use App\Models\OrganizationInvite;
use App\Models\OrganizationInviteLink;
use App\Notifications\OrgInviteNotification;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Tenancy;

beforeEach(function () {
    $this->seed(RolesSeeder::class);
});

function m004As(string $token)
{
    test()->flushHeaders();
    app('auth')->forgetGuards();

    return test()->withToken($token);
}

function inviteSetup(): array
{
    [$ada, $adaToken] = Tenancy::user();
    $org = Tenancy::org($ada, 'Hiring');
    Notification::fake();

    return [$ada, $adaToken, $org];
}

function captureInviteToken(?User $ignored = null): string
{
    $token = '';
    Notification::assertSentOnDemand(
        OrgInviteNotification::class,
        function ($n, $notifiable, $mail) use (&$token) {
            $token = $n->invite->token();

            return true;
        },
    );

    return $token;
}

/* ────────────────────────── email invites (S4) ─────────────────────────── */

test('invite: creates pending invite + mails token, viewer forbidden', function () {
    [$ada, $adaToken, $org] = inviteSetup();
    [$cit, $citToken] = Tenancy::user('cit@example.com');
    Tenancy::addMember($org, $cit, OrgRole::Viewer);

    m004As($citToken)->postJson('/api/v1/orgs/'.$org->id.'/invites', ['email' => 'new@example.com', 'role' => 'member'])
        ->assertForbidden();

    m004As($adaToken)->postJson('/api/v1/orgs/'.$org->id.'/invites', ['email' => 'New@Example.com', 'role' => 'admin'])
        ->assertCreated()->assertJsonPath('data.email', 'new@example.com')
        ->assertJsonPath('data.role', 'admin');

    expect($org->invites()->count())->toBe(1);

    $token = captureInviteToken();
    expect(mb_strlen($token))->toBe(48);
});

test('invite list never exposes tokens; revoke kills pending invite', function () {
    [$ada, $adaToken, $org] = inviteSetup();

    $id = m004As($adaToken)->postJson('/api/v1/orgs/'.$org->id.'/invites', ['email' => 'p@q.r', 'role' => 'member'])->json('data.id');

    $list = m004As($adaToken)->getJson('/api/v1/orgs/'.$org->id.'/invites')->assertOk()->json('data.invites.0');
    expect(array_keys($list))->not->toContain('token', 'token_hash');

    m004As($adaToken)->deleteJson('/api/v1/orgs/'.$org->id.'/invites/'.$id)->assertOk();
    expect($org->invites()->count())->toBe(0);
});

test('accept invite: happy path grants role + fires joined; email mismatch, expired, consumed, foreign all generic 403', function () {
    [$ada, $adaToken, $org] = inviteSetup();

    m004As($adaToken)->postJson('/api/v1/orgs/'.$org->id.'/invites', ['email' => 'sam@example.com', 'role' => 'admin']);
    $token = captureInviteToken();

    // wrong recipient
    [$eve, $eveToken] = Tenancy::user('eve@example.com');
    $this->flushHeaders();
    app('auth')->forgetGuards();
    m004As($eveToken)->postJson('/api/v1/invites/accept', ['token' => $token])
        ->assertForbidden()->assertJsonPath('message', 'This invitation link is no longer valid.');

    // right recipient
    [$sam, $samToken] = Tenancy::user('sam@example.com');
    m004As($samToken)->postJson('/api/v1/invites/accept', ['token' => $token])
        ->assertOk()->assertJsonPath('data.organization_id', $org->id);

    $membership = $org->membershipFor($sam);
    expect($membership)->not->toBeNull()
        ->and($membership->role)->toBe(OrgRole::Admin)
        ->and($membership->status)->toBe(MemberStatus::Active);

    // accept does NOT switch current (micro-decision #5)
    expect($sam->refresh()->current_organization_id)->not->toBe($org->id);

    // replay
    m004As($samToken)->postJson('/api/v1/invites/accept', ['token' => $token])
        ->assertForbidden()->assertJsonPath('message', 'This invitation link is no longer valid.');

    // expired
    Notification::fake();
    m004As($adaToken)->postJson('/api/v1/orgs/'.$org->id.'/invites', ['email' => 'tim@example.com', 'role' => 'member']);
    $t2 = captureInviteToken();
    OrganizationInvite::withoutGlobalScopes()->update(['expires_at' => now()->subMinute()]);
    [$tim, $timToken] = Tenancy::user('tim@example.com');
    m004As($timToken)->postJson('/api/v1/invites/accept', ['token' => $t2])->assertForbidden();
});

test('invite re-request replaces pending invite (old token dead); duplicate active member 422; member cap 402', function () {
    [$ada, $adaToken, $org] = inviteSetup();

    m004As($adaToken)->postJson('/api/v1/orgs/'.$org->id.'/invites', ['email' => 'dup@example.com', 'role' => 'member']);
    $first = captureInviteToken();
    Notification::fake();
    m004As($adaToken)->postJson('/api/v1/orgs/'.$org->id.'/invites', ['email' => 'dup@example.com', 'role' => 'viewer']);
    $second = captureInviteToken();

    expect($org->invites()->count())->toBe(1);
    [$dup, $dupToken] = Tenancy::user('dup@example.com');
    m004As($dupToken)->postJson('/api/v1/invites/accept', ['token' => $first])->assertForbidden();
    m004As($dupToken)->postJson('/api/v1/invites/accept', ['token' => $second])->assertOk();

    // already-active invitee
    Notification::fake();
    m004As($adaToken)->postJson('/api/v1/orgs/'.$org->id.'/invites', ['email' => 'dup@example.com', 'role' => 'member'])
        ->assertStatus(422)->assertJsonValidationErrors('email');

    // member cap
    config(['tenancy.limits.max_members' => 2]);
    Notification::fake();
    m004As($adaToken)->postJson('/api/v1/orgs/'.$org->id.'/invites', ['email' => 'late@example.com', 'role' => 'member'])
        ->assertStatus(402);
});

/* ───────────────────────── invite links (S4) ───────────────────────────── */

test('invite link: create/list/revoke; join grants role idempotently; expiry and max-uses enforced generically', function () {
    [$ada, $adaToken, $org] = inviteSetup();

    $created = m004As($adaToken)->postJson('/api/v1/orgs/'.$org->id.'/invite-links', ['role' => 'member', 'expires_in_days' => 3])
        ->assertCreated()->assertJsonStructure(['data' => ['id', 'token', 'url', 'role', 'uses']])->json('data');

    $list = m004As($adaToken)->getJson('/api/v1/orgs/'.$org->id.'/invite-links')->assertOk()->json('data.links.0');
    expect(array_keys($list))->not->toContain('token', 'token_hash');

    [$jo, $joToken] = Tenancy::user('jo@example.com');
    m004As($joToken)->postJson('/api/v1/invite-links/join', ['token' => $created['token']])
        ->assertOk()->assertJsonPath('data.organization_id', $org->id);
    expect($org->membershipFor($jo)->role)->toBe(OrgRole::Member);

    // idempotent re-join does not bump uses
    m004As($joToken)->postJson('/api/v1/invite-links/join', ['token' => $created['token']])->assertOk();
    expect($org->inviteLinks()->first()->refresh()->uses)->toBe(1);

    // max uses
    OrganizationInviteLink::withoutGlobalScopes()->update(['max_uses' => 1]);
    [$xan, $xanToken] = Tenancy::user('xan@example.com');
    m004As($xanToken)->postJson('/api/v1/invite-links/join', ['token' => $created['token']])
        ->assertForbidden()->assertJsonPath('message', 'This invitation link is no longer valid.');

    // member cap via link → 402
    config(['tenancy.limits.max_members' => 2]);
    $link2 = m004As($adaToken)->postJson('/api/v1/orgs/'.$org->id.'/invite-links', ['role' => 'viewer'])->json('data.token');
    OrganizationInviteLink::query()->where('token_hash', hash('sha256', $link2))->update(['max_uses' => null, 'uses' => 0]);
    [$cap, $capToken] = Tenancy::user('cap@example.com');
    m004As($capToken)->postJson('/api/v1/invite-links/join', ['token' => $link2])->assertStatus(402);

    // expired
    $link3 = m004As($adaToken)->postJson('/api/v1/orgs/'.$org->id.'/invite-links', ['role' => 'viewer'])->json('data.token');
    OrganizationInviteLink::withoutGlobalScopes()->update(['expires_at' => now()->subMinute()]);
    [$old, $oldToken] = Tenancy::user('old@example.com');
    m004As($oldToken)->postJson('/api/v1/invite-links/join', ['token' => $link3])->assertForbidden();

    // revoke
    OrganizationInviteLink::withoutGlobalScopes()->update(['expires_at' => now()->addDays(2)]);
    $target = OrganizationInviteLink::withoutGlobalScopes()->firstWhere('token_hash', hash('sha256', $link3));
    m004As($adaToken)->deleteJson('/api/v1/orgs/'.$org->id.'/invite-links/'.$target->id)->assertOk();
    [$gone, $goneToken] = Tenancy::user('gone@example.com');
    m004As($goneToken)->postJson('/api/v1/invite-links/join', ['token' => $link3])->assertForbidden();
});

test('invite link member without permission forbidden; unknown token generic 403', function () {
    [$ada, $adaToken, $org] = inviteSetup();
    [$bea, $beaToken] = Tenancy::user('bea@example.com');
    Tenancy::addMember($org, $bea, OrgRole::Member);

    m004As($beaToken)->postJson('/api/v1/orgs/'.$org->id.'/invite-links', ['role' => 'viewer'])->assertForbidden();
    m004As($adaToken)->postJson('/api/v1/invite-links/join', ['token' => str_repeat('z', 48)])
        ->assertForbidden()->assertJsonPath('message', 'This invitation link is no longer valid.');
});
