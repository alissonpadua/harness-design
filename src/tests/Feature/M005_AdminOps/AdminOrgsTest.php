<?php

declare(strict_types=1);

namespace Tests\Feature\M005_AdminOps;

use App\Actions\Org\DeleteOrganizationAction;
use App\Contracts\Org\PlanOrgEntitlements;
use App\Models\User;
use App\Notifications\CatalogDelivery;
use Database\Seeders\PlansSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\M003_Billing\BillingHelp;
use Tests\Support\Tenancy;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    $this->seed(PlansSeeder::class);
});

function orgAdmin(): array
{
    $root = User::factory()->create(['email' => 'root@o.test']);
    $root->assignRole('super-admin');

    return [$root, $root->createToken('cli', ['*'])->plainTextToken];
}

function orgAs(string $token)
{
    test()->flushHeaders();
    app('auth')->forgetGuards();

    return test()->withToken($token);
}

test('AC-005.5 admin plan change: gateway cancel, local swap, domain event, audit', function () {
    [, $rt] = orgAdmin();
    [$ada] = Tenancy::user();
    $org = Tenancy::org($ada, 'GrantCo'); // helper puts it on pro with fake remote sub

    $org->subscription()->forceFill(['gateway_subscription_id' => 'fake_sub_live'])->save();

    Notification::fake();
    orgAs($rt)->postJson('/admin/v1/orgs/'.$org->id.'/plan', ['plan_code' => 'business'])
        ->assertOk()
        ->assertJsonPath('data.plan', 'business')
        ->assertJsonPath('data.admin_locked', true);

    $sub = $org->refresh()->subscription();
    expect($sub->plan->code)->toBe('business')
        ->and($sub->admin_locked)->toBeTrue()
        ->and($sub->gateway_subscription_id)->toBe('fake_sub_live'); // retained so late webhooks route (and get skipped)

    // fake gateway saw the cancel
    expect(BillingHelp::gateway()->subscriptions[$org->id] ?? null)->toBeNull();

    Notification::assertSentTo($ada, CatalogDelivery::class,
        fn (CatalogDelivery $n) => $n->type === 'billing.plan_changed');

    $row = DB::table('activity_log')->where('event', 'org_plan_change')->first();
    expect($row)->not->toBeNull()
        ->and(json_decode((string) $row->properties, true)['detached_gateway_subscription'])->toBe('fake_sub_live');

    orgAs($rt)->postJson('/admin/v1/orgs/'.$org->id.'/plan', ['plan_code' => 'business'])->assertStatus(422);
    orgAs($rt)->postJson('/admin/v1/orgs/'.$org->id.'/plan', ['plan_code' => 'ghost'])->assertNotFound();
});

test('AC-005.5 admin_locked makes later webhooks skip and a fresh checkout wins', function () {
    [, $rt] = orgAdmin();
    [$ada, $adaToken] = Tenancy::user();
    $org = Tenancy::org($ada, 'LockCo');
    $org->subscription()->forceFill(['gateway_subscription_id' => 'fake_sub_lock'])->save();
    orgAs($rt)->postJson('/admin/v1/orgs/'.$org->id.'/plan', ['plan_code' => 'business'])->assertOk();

    $gateway = BillingHelp::gateway();

    // stray webhook for the detached old sub: stored, no mutation
    [$raw, $sig] = $gateway->subscriptionUpdated($org, ['id' => 'fake_sub_lock', 'status' => 'canceled']);
    $before = $org->refresh()->subscription();
    $r = $this->call('POST', '/api/v1/billing/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_SIGNATURE' => $sig], $raw);
    $r->assertOk();
    expect($r->json('data.outcome'))->toBe('skipped_locked')
        ->and($org->refresh()->subscription()->status->value)->toBe('active');
    unset($before);

    // org's own self-service change supersedes the grant (clears lock)
    orgAs($adaToken)->postJson("/api/v1/orgs/{$org->id}/billing/subscription/change", ['plan_code' => 'pro'])->assertOk();
    expect($org->refresh()->subscription()->admin_locked)->toBeFalse();
});

test('AC-005.6 entitlement overrides: subset merge, live effect, clear, validation, audit', function () {
    [, $rt] = orgAdmin();
    [$ada] = Tenancy::user();
    $org = Tenancy::org($ada, 'OverCo'); // pro: 20 members
    $entitlements = app(PlanOrgEntitlements::class);
    expect($entitlements->maxMembers($org))->toBe(20);

    orgAs($rt)->putJson('/admin/v1/orgs/'.$org->id.'/entitlements', ['entitlements' => ['max_members_per_org' => 50]])
        ->assertOk()->assertJsonPath('data.override.max_members_per_org', 50);
    expect($entitlements->maxMembers($org))->toBe(50);

    // partial second write merges, does not wipe
    orgAs($rt)->putJson('/admin/v1/orgs/'.$org->id.'/entitlements', ['entitlements' => ['webhooks' => false]])
        ->assertOk()->assertJsonPath('data.override.max_members_per_org', 50);

    orgAs($rt)->putJson('/admin/v1/orgs/'.$org->id.'/entitlements', ['entitlements' => ['max_teams' => 0]])->assertStatus(422);
    orgAs($rt)->putJson('/admin/v1/orgs/'.$org->id.'/entitlements', ['entitlements' => ['bogus' => 1]])->assertStatus(422);

    // clears
    orgAs($rt)->putJson('/admin/v1/orgs/'.$org->id.'/entitlements', ['entitlements' => []])->assertOk()->assertJsonPath('data.override', []);
    expect($entitlements->maxMembers($org))->toBe(20);

    expect(DB::table('activity_log')->where('event', 'org_entitlement_override')->count())->toBeGreaterThanOrEqual(3);
});

test('AC-005.4/S1 orgs list, detail, restore; non-admin 403', function () {
    [$root, $rt] = orgAdmin();
    [$ada] = Tenancy::user();
    $org = Tenancy::org($ada, 'ListCo');

    orgAs($rt)->getJson('/admin/v1/orgs?q=list')->assertOk()->assertJsonPath('data.organizations.0.slug', 'listco');
    $detail = orgAs($rt)->getJson('/admin/v1/orgs/'.$org->id)->assertOk()
        ->assertJsonPath('data.subscription.plan', 'pro');
    expect($detail->json('data.effective_entitlements.max_teams'))->toBe(5);

    // delete then restore
    app(DeleteOrganizationAction::class)->handle($org, 'ListCo');
    orgAs($rt)->getJson('/admin/v1/orgs/'.$org->id)->assertOk()->assertJsonPath('data.deleted_at', fn ($v) => $v !== null);
    orgAs($rt)->postJson('/admin/v1/orgs/'.$org->id.'/restore')->assertOk();
    expect($org->refresh()->trashed())->toBeFalse();
    orgAs($rt)->postJson('/admin/v1/orgs/'.$org->id.'/restore')->assertStatus(422);

    $plain = User::factory()->create(['email' => 'peasant@o.test']);
    orgAs($plain->createToken('cli', ['*'])->plainTextToken)->getJson('/admin/v1/orgs')->assertForbidden();
    unset($root);
});
