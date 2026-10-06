<?php

declare(strict_types=1);

use App\Contracts\Org\OrgEntitlements;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Database\Seeders\RolesSeeder;
use Tests\Feature\M003_Billing\BillingHelp;
use Tests\Support\Tenancy;

beforeEach(function () {
    $this->seed(PlansSeeder::class);
});

function adminToken(): string
{
    test()->seed(RolesSeeder::class);
    $admin = User::factory()->create(['email' => 'root@m3.test']);
    $admin->assignRole('super-admin');

    return $admin->createToken('cli', ['*'])->plainTextToken;
}

test('AC-003.3 admin plan CRUD without deploy', function () {
    $token = adminToken();

    BillingHelp::as($token)->getJson('/admin/v1/plans')
        ->assertOk()->assertJsonCount(3, 'data.plans');

    $pro = Plan::whereCode('pro')->firstOrFail();
    BillingHelp::as($token)->getJson('/admin/v1/plans/'.$pro->id)
        ->assertOk()
        ->assertJsonCount(6, 'data.prices')
        ->assertJsonPath('data.entitlements.max_teams', 5);

    // store a new plan with prices
    BillingHelp::as($token)->postJson('/admin/v1/plans', [
        'code' => 'startup', 'name' => 'Startup', 'trial_days' => 7,
        'entitlements' => ['max_teams' => 2, 'max_members_per_org' => 10, 'webhooks' => true, 'audit_retention_days' => 30, 'api_rate_limit_per_min' => 300],
        'prices' => [
            ['currency' => 'usd', 'interval' => 'monthly', 'amount' => 900],
            ['currency' => 'usd', 'interval' => 'annual', 'amount' => 9000],
        ],
    ])->assertCreated()->assertJsonPath('data.code', 'startup');

    // update: rename, tweak entitlement, upsert a EUR price
    $startup = Plan::whereCode('startup')->firstOrFail();
    BillingHelp::as($token)->patchJson('/admin/v1/plans/'.$startup->id, [
        'name' => 'Startup Plus',
        'entitlements' => ['max_teams' => 3, 'max_members_per_org' => 10, 'webhooks' => true, 'audit_retention_days' => 30, 'api_rate_limit_per_min' => 300],
        'prices' => [['currency' => 'EUR', 'interval' => 'monthly', 'amount' => 800]],
    ])->assertOk()
        ->assertJsonPath('data.name', 'Startup Plus')
        ->assertJsonPath('data.entitlements.max_teams', 3);

    expect($startup->refresh()->price('EUR', 'monthly')?->amount)->toBe(800);
});

test('admin plan validation: bad interval + duplicate code + shape', function () {
    $token = adminToken();

    BillingHelp::as($token)->postJson('/admin/v1/plans', [
        'code' => 'bad1', 'name' => 'Bad',
        'entitlements' => ['max_teams' => 1, 'max_members_per_org' => 1, 'webhooks' => false, 'audit_retention_days' => 0, 'api_rate_limit_per_min' => 1],
        'prices' => [['currency' => 'USD', 'interval' => 'weekly', 'amount' => 1]],
    ])->assertStatus(422);

    BillingHelp::as($token)->postJson('/admin/v1/plans', [
        'code' => 'pro', 'name' => 'Dup',
        'entitlements' => ['max_teams' => 1, 'max_members_per_org' => 1, 'webhooks' => false, 'audit_retention_days' => 0, 'api_rate_limit_per_min' => 1],
    ])->assertStatus(422);

    BillingHelp::as($token)->postJson('/admin/v1/plans', [
        'code' => 'bad2', 'name' => 'Bad2', 'prices' => [],
    ])->assertStatus(422);
});

test('non-super-admin cannot reach plan admin routes', function () {
    [, $token] = Tenancy::user('normie@m3.test');

    BillingHelp::as($token)->getJson('/admin/v1/plans')->assertForbidden();
});

test('price/entitlement changes take effect for orgs immediately (no deploy)', function () {
    $token = adminToken();
    [, $memberToken, $org] = BillingHelp::userOrg();

    // business currently allows 100 members; shrink it to 2
    $business = Plan::whereCode('business')->firstOrFail();
    BillingHelp::as($token)->patchJson('/admin/v1/plans/'.$business->id, [
        'entitlements' => ['max_teams' => 25, 'max_members_per_org' => 2, 'webhooks' => true, 'audit_retention_days' => 365, 'api_rate_limit_per_min' => 3000],
    ])->assertOk();

    BillingHelp::attachPlan($org, 'business');
    expect(app(OrgEntitlements::class)->maxMembers($org))->toBe(2);
    unset($memberToken);
});
