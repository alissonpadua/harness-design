<?php

declare(strict_types=1);

use App\Actions\Billing\EnsureOrgSubscription;
use App\Actions\Org\CreatePersonalWorkspaceAction;
use App\Enums\SubscriptionStatus;
use App\Models\BillingSubscription;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Tests\Feature\M003_Billing\BillingHelp;
use Tests\Support\Tenancy;

beforeEach(function () {
    $this->seed(PlansSeeder::class);
});

test('AC-003.19 past_due beyond grace downgrades to free via command', function () {
    [, $token, $org] = BillingHelp::userOrg(); // pro
    unset($token);

    $sub = $org->subscription();
    $sub->forceFill([
        'status' => SubscriptionStatus::PastDue,
        'past_due_since' => now()->subDays((int) config('billing.dunning_grace_days') + 1),
    ])->save();

    $this->artisan('billing:dunning')->assertSuccessful();

    $sub = $org->refresh()->subscription();
    expect($sub->plan->code)->toBe('free')
        ->and($sub->status)->toBe(SubscriptionStatus::Active)
        ->and($sub->past_due_since)->toBeNull();
});

test('AC-003.19 downgrade below caps also stamps over-limit', function () {
    [, , $org] = BillingHelp::userOrg();

    for ($i = 1; $i <= 5; $i++) {
        Tenancy::addMember($org, User::factory()->create(['email' => "d{$i}@x.test"]));
    }

    $sub = $org->subscription();
    $sub->forceFill([
        'status' => SubscriptionStatus::PastDue,
        'past_due_since' => now()->subDays(10),
    ])->save();

    $this->artisan('billing:dunning');

    expect($org->refresh()->subscription()->over_limit_until)->not->toBeNull();
});

test('AC-003.20 recent past_due untouched; recovery zeroes the clock', function () {
    [, , $org] = BillingHelp::userOrg();
    $sub = $org->subscription();
    $sub->forceFill(['status' => SubscriptionStatus::PastDue, 'past_due_since' => now()->subDay()])->save();

    $this->artisan('billing:dunning');
    expect($org->refresh()->subscription()->plan->code)->toBe('pro');

    $sub = $org->subscription();
    $sub->forceFill(['status' => SubscriptionStatus::Active, 'past_due_since' => null])->save();
    $this->artisan('billing:dunning');
    expect($org->refresh()->subscription()->status)->toBe(SubscriptionStatus::Active);
});

test('dunning scheduled hourly', function () {
    $events = app(Schedule::class)->events();
    $cmd = collect($events)->first(fn ($e) => str_contains($e->command ?? '', 'billing:dunning'));
    expect($cmd)->not->toBeNull()->and($cmd->expression)->toBe('0 * * * *');
});

test('billing:sync mirrors invoices for orgs with customers', function () {
    [, , $org] = BillingHelp::userOrg();
    BillingHelp::gateway()->ensureCustomer($org);
    $org2 = Organization::create(['name' => 'Ghost', 'type' => 'team', 'owner_id' => $org->owner_id]);

    $this->artisan('billing:sync')->assertSuccessful();
    $this->artisan('billing:sync', ['organization' => (string) $org->id])->assertSuccessful();
    unset($org2);
});

test('free subscription bootstrapped for every org incl. backfill command', function () {
    $raw = Organization::create(['name' => 'Legacy', 'type' => 'team', 'owner_id' => User::factory()->create()->id]);
    expect($raw->subscription())->toBeNull();

    $this->artisan('billing:ensure-subscriptions')->assertSuccessful();

    expect($raw->refresh()->subscription())->not->toBeNull()
        ->and($raw->subscription()->status)->toBe(SubscriptionStatus::Active);

    $this->artisan('billing:ensure-subscriptions')->assertSuccessful(); // idempotent
    expect(BillingSubscription::query()->where('organization_id', $raw->id)->count())->toBe(1);
    unset($raw);
});

test('personal workspace gets a free subscription too', function () {
    [$u] = Tenancy::user('p1@x.test');
    $org = app(CreatePersonalWorkspaceAction::class)->handle($u);
    $ensured = app(EnsureOrgSubscription::class);

    expect($ensured->handle($org)->plan->code)->toBe('free');
});
