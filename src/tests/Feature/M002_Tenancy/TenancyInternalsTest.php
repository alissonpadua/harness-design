<?php

declare(strict_types=1);

use App\Actions\Org\CreatePersonalWorkspaceAction;
use App\Auth\OrgAuthorizer;
use App\Enums\MemberStatus;
use App\Enums\OrgRole;
use App\Models\OrganizationInvite;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Notifications\NotificationCatalog;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Tenancy;

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    Notification::fake();
});

function m008As(string $token)
{
    test()->flushHeaders();
    app('auth')->forgetGuards();

    return test()->withToken($token);
}

test('unknown or foreign targets on role/suspend endpoints are uniform 404s', function () {
    [$ada, $token] = Tenancy::user();
    $org = Tenancy::org($ada, 'Guards');
    [$bob] = Tenancy::user('bob@example.com');
    $outsider = User::factory()->create(['email' => 'out@example.com']);

    m008As($token)->patchJson('/api/v1/orgs/'.$org->id.'/members/999999', ['role' => 'admin'])->assertNotFound();
    m008As($token)->patchJson('/api/v1/orgs/'.$org->id.'/members/'.$outsider->id, ['role' => 'admin'])->assertNotFound();
    m008As($token)->postJson('/api/v1/orgs/'.$org->id.'/members/'.$bob->id.'/suspend')->assertNotFound();
    m008As($token)->postJson('/api/v1/orgs/'.$org->id.'/members/'.$bob->id.'/unsuspend')->assertNotFound();
    m008As($token)->deleteJson('/api/v1/orgs/'.$org->id.'/members/'.$bob->id)->assertNotFound();
});

test('suspended member cannot even read member list (base request guard)', function () {
    [$ada, $token] = Tenancy::user();
    $org = Tenancy::org($ada);
    [$bea, $beaToken] = Tenancy::user('bea@example.com');
    Tenancy::addMember($org, $bea, OrgRole::Admin, MemberStatus::Suspended);

    m008As($beaToken)->getJson('/api/v1/orgs/'.$org->id.'/members')
        ->assertForbidden()->assertJsonPath('message', 'This action is unauthorized.');
});

test('OrgAuthorizer unit: no membership, suspended, wildcard and explicit grants', function () {
    [$ada] = Tenancy::user();
    $org = Tenancy::org($ada);
    $authorizer = app(OrgAuthorizer::class);
    $stranger = User::factory()->create();

    expect($authorizer->can($stranger, $org, 'org.view'))->toBeFalse()
        ->and($authorizer->can($ada, $org, 'org.transfer'))->toBeTrue();

    [$sus] = Tenancy::user('sus@example.com');
    Tenancy::addMember($org, $sus, OrgRole::Admin, MemberStatus::Suspended);
    expect($authorizer->can($sus, $org, 'members.invite'))->toBeFalse();

    [$view] = Tenancy::user('view@example.com');
    Tenancy::addMember($org, $view, OrgRole::Viewer);
    expect($authorizer->can($view, $org, 'org.view'))->toBeTrue()
        ->and($authorizer->can($view, $org, 'org.update'))->toBeFalse();
});

test('BelongsToOrganization creating hook defaults to current org', function () {
    [$ada] = Tenancy::user();
    $org = Tenancy::org($ada);
    Tenancy::switchTo($ada, $org);
    $this->actingAs($ada);

    $invite = OrganizationInvite::makeWithToken();
    $invite->forceFill(['email' => 'auto@fill.me', 'role' => 'member', 'invited_by' => $ada->id, 'expires_at' => now()->addDay()])->save();

    expect($invite->refresh()->organization_id)->toBe($org->id);
});

test('OrganizationScope skips console-without-auth (seeder context) and applies with auth', function () {
    [$ada] = Tenancy::user();
    $org = Tenancy::org($ada);

    $model = new OrganizationInvite;

    // no authenticated user in console → exempt (no constraint added)
    $bare = $model->newQuery();
    (new OrganizationScope)->apply($bare, $model);
    expect(array_values(array_filter($bare->getQuery()->wheres, fn ($w) => ($w['column'] ?? '') === 'organization_invites.organization_id')))->toBe([]);

    // authenticated (acting user) → current org applied
    $this->actingAs($ada);
    Tenancy::switchTo($ada, $org);
    $scoped = $model->newQuery();
    (new OrganizationScope)->apply($scoped, $model);
    $cols = array_values(array_filter($scoped->getQuery()->wheres, fn ($w) => ($w['column'] ?? '') === 'organization_invites.organization_id'));
    expect($cols[0]['value'] ?? null)->toBe($org->id);
});

test('invite notification renders org name, role and token', function () {
    [$ada] = Tenancy::user();
    $org = Tenancy::org($ada, 'Mailer Co');
    $invite = OrganizationInvite::makeWithToken();
    $invite->forceFill(['organization_id' => $org->id, 'email' => 'm@mm.io', 'role' => 'admin', 'invited_by' => $ada->id, 'expires_at' => now()->addDay()])->save();

    $mail = app(NotificationCatalog::class)->get('org.invite_received')->mailable([
        'org_name' => $org->name, 'role' => 'admin', 'url' => 'http://api.test/accept-invite/'.$invite->token(),
    ]);

    $rendered = $mail->render();
    expect($mail->envelope()->subject)->toBe('You were invited to Mailer Co')
        ->and(implode(' ', $mail->buildViewData()['lines']))->toContain('admin')
        ->and($rendered)->toContain($invite->token());
});

test('personal workspace replay backfills a null current pointer', function () {
    $user = User::factory()->create();
    $first = app(CreatePersonalWorkspaceAction::class)->handle($user);
    $user->forceFill(['current_organization_id' => null])->save();

    $again = app(CreatePersonalWorkspaceAction::class)->handle($user);

    expect($again->id)->toBe($first->id)
        ->and($user->refresh()->current_organization_id)->toBe($first->id);
});

test('remove: sole owner protected; removed member current pointer resets to personal', function () {
    [$ada, $token] = Tenancy::user();
    $org = Tenancy::org($ada, 'Protected');
    [$dee, $deeToken] = Tenancy::user('dee@example.com');
    $personal = Tenancy::personalWorkspace($dee);
    Tenancy::addMember($org, $dee, OrgRole::Member);
    m008As($deeToken)->postJson('/api/v1/orgs/'.$org->id.'/switch')->assertOk();

    m008As($token)->deleteJson('/api/v1/orgs/'.$org->id.'/members/'.$ada->id)
        ->assertStatus(422)->assertJsonValidationErrors('user');

    m008As($token)->deleteJson('/api/v1/orgs/'.$org->id.'/members/'.$dee->id)->assertOk();
    expect($dee->refresh()->current_organization_id)->toBe($personal->id);
});

test('renderer passes through custom HttpException statuses with fallback text', function () {
    Route::get('api/v1/__t_teapot', fn () => throw new HttpException(418));

    $this->getJson('/api/v1/__t_teapot')->assertStatus(418)->assertJsonPath('message', "I'm a teapot");
});
