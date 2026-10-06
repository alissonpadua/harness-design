<?php

declare(strict_types=1);

use App\Contracts\Org\OrgEntitlements;
use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\BillingSubscription;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\M003_Billing\BillingHelp;
use Tests\Support\Tenancy;

beforeEach(function () {
    $this->seed(PlansSeeder::class);
});

test('AC-003.1 seeder creates the locked plan matrix, idempotent', function () {
    expect(Plan::count())->toBe(3)
        ->and(PlanPrice::count())->toBe(18);

    $pro = Plan::whereCode('pro')->firstOrFail();
    expect($pro->entitlements->max_teams)->toBe(5)
        ->and($pro->entitlements->api_rate_limit_per_min)->toBe(600)
        ->and($pro->price('BRL', 'annual')?->amount)->toBe(99000);

    $this->seed(PlansSeeder::class);
    expect(Plan::count())->toBe(3)->and(PlanPrice::count())->toBe(18);
});

test('money ints + unique price rows + one subscription per org', function () {
    $pro = Plan::whereCode('pro')->firstOrFail();

    expect(fn () => PlanPrice::create([
        'plan_id' => $pro->id, 'currency' => 'USD', 'interval' => 'monthly', 'amount' => 1,
    ]))->toThrow(UniqueConstraintViolationException::class);

    [, , $org] = BillingHelp::userOrg();

    expect(fn () => BillingSubscription::create([
        'organization_id' => $org->id,
        'plan_id' => Plan::whereCode('business')->firstOrFail()->id,
        'status' => SubscriptionStatus::Active->value,
        'interval' => 'monthly',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('AC-003.4 entitlement cast rejects unknown and missing keys', function () {
    $this->expectException(InvalidArgumentException::class);

    Plan::create([
        'code' => 'weird', 'name' => 'Weird',
        'entitlements' => [
            'max_teams' => 2, 'max_members_per_org' => 5, 'webhooks' => true,
            'audit_retention_days' => 0, 'api_rate_limit_per_min' => 60, 'magic' => true,
        ],
    ]);
});

test('AC-003.2 entitlements come from plans; best plan across owned orgs; free floor', function () {
    [$ada, , $org] = BillingHelp::userOrg();
    $entitlements = app(OrgEntitlements::class);

    expect($entitlements->maxMembers($org))->toBe(20)
        ->and($entitlements->maxTeams($ada))->toBe(5);

    BillingHelp::attachPlan($org, 'free');
    expect($entitlements->maxMembers($org))->toBe(3);

    [$lone] = Tenancy::user('lone@example.com');
    $loneOrg = Organization::create(['name' => 'Bare', 'type' => 'team', 'owner_id' => $lone->id]);
    expect($entitlements->maxMembers($loneOrg))->toBe(3)
        ->and($entitlements->maxTeams($lone))->toBe(1);
});

test('AC-003.21/.22 over-limit stamps on downgrade below usage, clears when compliant', function () {
    [, $token, $org] = BillingHelp::userOrg();

    for ($i = 1; $i <= 6; $i++) {
        Tenancy::addMember($org, User::factory()->create(['email' => "m{$i}@x.test"]));
    }

    BillingHelp::as($token)
        ->postJson("/api/v1/orgs/{$org->id}/billing/subscription/change", ['plan_code' => 'free'])
        ->assertOk()->assertJsonPath('data.changed', true);

    $sub = $org->refresh()->subscription();
    expect($sub->plan->code)->toBe('free')
        ->and($sub->over_limit_until)->not->toBeNull();

    BillingHelp::as($token)
        ->postJson("/api/v1/orgs/{$org->id}/billing/subscription/change", ['plan_code' => 'business'])
        ->assertOk();

    expect($org->refresh()->subscription()->over_limit_until)->toBeNull();
});

test('over-limit blocks adds but never touches existing rows', function () {
    [, $token, $org] = BillingHelp::userOrg();
    Tenancy::addMember($org, User::factory()->create(['email' => 'big@x.test']));
    Tenancy::addMember($org, User::factory()->create(['email' => 'big2@x.test']));

    BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/billing/subscription/change", ['plan_code' => 'free'])->assertOk();

    Notification::fake();
    BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/invites", ['email' => 'extra@x.test', 'role' => 'member'])
        ->assertStatus(402);

    expect($org->members()->count())->toBe(3);
});

test('billing interval maps to stripe units', function () {
    expect(BillingInterval::Monthly->stripeInterval())->toBe('month')
        ->and(BillingInterval::Annual->stripeInterval())->toBe('year');
});
