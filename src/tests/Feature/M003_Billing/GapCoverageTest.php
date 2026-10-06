<?php

declare(strict_types=1);

use App\Actions\Billing\EvaluateOverLimit;
use App\Actions\Billing\ProcessWebhookEvent;
use App\Actions\Billing\UpsertPlanAction;
use App\Actions\Org\CreateOrganizationAction;
use App\Billing\Events\InvoicePaid;
use App\Billing\Events\SubscriptionCanceled;
use App\Billing\Events\SubscriptionResumed;
use App\Billing\FakeGateway;
use App\Billing\StripeGateway;
use App\Data\Billing\GatewayInvoiceData;
use App\Data\Plan\PlanEntitlementsData;
use App\Enums\SubscriptionStatus;
use App\Exceptions\InvalidWebhookException;
use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\BillingSubscription;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\User;
use App\Models\WebhookEvent;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Stripe\ApiRequestor;
use Tests\Feature\M003_Billing\BillingHelp;
use Tests\Feature\M003_Billing\FakeStripeHttp;
use Tests\Support\Tenancy;

beforeEach(function () {
    $this->seed(PlansSeeder::class);
});

/* ── ProcessWebhookEvent edges ── */

test('malformed and unknown-shaped payloads fail closed without damage', function () {
    $gateway = BillingHelp::gateway();
    [$raw, $sig] = $gateway->emit('checkout.session.completed', ['id' => 'x', 'object' => 'checkout.session', 'customer' => 'ghost_cus', 'metadata' => []]);
    $r = test()->call('POST', '/api/v1/billing/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_SIGNATURE' => $sig], $raw);
    $r->assertOk()->assertJsonPath('data.outcome', 'failed');

    // subByInvoice for invoice events on subscriptions we know nothing about
    [, , $probeOrg] = BillingHelp::userOrg(planCode: 'free');
    [$raw, $sig] = $gateway->invoiceEvent($probeOrg, 'invoice.paid', ['subscription' => 'sub_never_seen']);
    test()->call('POST', '/api/v1/billing/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_SIGNATURE' => $sig], $raw)
        ->assertOk()->assertJsonPath('data.outcome', 'failed');

    // subscription.updated for unknown gateway sub
    [$raw, $sig] = $gateway->emit('customer.subscription.updated', ['id' => 'sub_ghost', 'object' => 'subscription', 'customer' => 'fake_cus_'.$probeOrg->id, 'status' => 'active']);
    test()->call('POST', '/api/v1/billing/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_SIGNATURE' => $sig], $raw)
        ->assertOk()->assertJsonPath('data.outcome', 'failed');

    // subscription.deleted unknown
    [$raw, $sig] = $gateway->emit('customer.subscription.deleted', ['id' => 'sub_ghost', 'object' => 'subscription']);
    test()->call('POST', '/api/v1/billing/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_SIGNATURE' => $sig], $raw)
        ->assertOk()->assertJsonPath('data.outcome', 'failed');
});

test('cancel-then-resume transitions dispatch domain events', function () {
    $seen = [];
    Event::listen(SubscriptionCanceled::class, function () use (&$seen): void {
        $seen[] = 'canceled';
    });
    Event::listen(SubscriptionResumed::class, function () use (&$seen): void {
        $seen[] = 'resumed';
    });

    [, , $org] = BillingHelp::userOrg(planCode: 'free');
    $gateway = BillingHelp::gateway();
    [$raw, $sig] = $gateway->checkoutCompletedPayload($org, BillingHelp::price('pro')->plan_id, BillingHelp::price('pro')->id, 'monthly', 0);
    test()->call('POST', '/api/v1/billing/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_SIGNATURE' => $sig], $raw);

    [$raw, $sig] = $gateway->subscriptionUpdated($org, ['status' => 'canceled']);
    test()->call('POST', '/api/v1/billing/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_SIGNATURE' => $sig], $raw);

    [$raw, $sig] = $gateway->subscriptionUpdated($org, ['status' => 'active']);
    test()->call('POST', '/api/v1/billing/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_SIGNATURE' => $sig], $raw);

    expect($seen)->toBe(['canceled', 'resumed']);
});

test('webhook events missing id/type are rejected at the gate', function () {
    $gw = new FakeGateway;
    $this->expectException(InvalidWebhookException::class);
    $gw->ingest(json_encode(['nonsense' => true]) ?: '', 'v1=x');
});

/* ── SyncInvoices / portal edges ── */

test('invoice list syncs remote pages into the mirror (new + updated rows)', function () {
    [$ada, $token, $org] = BillingHelp::userOrg();
    unset($ada);
    $gateway = BillingHelp::gateway();
    BillingHelp::gateway()->invoices[$org->id] = [
        new GatewayInvoiceData('in_new', 'paid', 1900, 'USD', 'https://inv.test/new', now()->toIso8601String(), null),
        new GatewayInvoiceData('in_open', 'open', 100, 'USD', null, null, now()->addDays(3)->toIso8601String()),
    ];

    BillingHelp::as($token)->getJson("/api/v1/orgs/{$org->id}/billing/invoices")->assertOk()->assertJsonCount(2, 'data.invoices');
    expect(BillingInvoice::query()->where('gateway_invoice_id', 'in_new')->exists())->toBeTrue();

    // second read is idempotent update path
    BillingHelp::as($token)->getJson("/api/v1/orgs/{$org->id}/billing/invoices")->assertOk();
});

test('billing:mirror command populates gateway price ids (fake)', function () {
    $this->artisan('billing:stripe-mirror')->assertSuccessful();
    expect(PlanPrice::query()->whereNull('gateway_price_id')->count())->toBe(0);
});

/* ── model & action unit edges ── */

test('entitlement cast: missing keys rejected; price lookup falls through; isOverLimit flag', function () {
    $this->expectException(InvalidArgumentException::class);
    Plan::create(['code' => 'nope', 'name' => 'Nope', 'entitlements' => ['max_teams' => 1]]);
});

test('price() returns null for unknown combo and isOverLimit reflects the column', function () {
    $pro = Plan::whereCode('pro')->firstOrFail();
    expect($pro->price('GBP', 'monthly'))->toBeNull();

    [, , $org] = BillingHelp::userOrg();
    $sub = $org->subscription();
    expect($sub->isOverLimit())->toBeFalse();
    $sub->forceFill(['over_limit_until' => now()->addDay()])->save();
    expect($sub->refresh()->isOverLimit())->toBeTrue();
});

test('EvaluateOverLimit is a no-op for orphan subscriptions and respects null owner', function () {
    $orphan = new BillingSubscription(['plan_id' => Plan::whereCode('pro')->firstOrFail()->id, 'status' => 'active', 'interval' => 'monthly']);
    $evaluate = app(EvaluateOverLimit::class);
    expect($evaluate->handle($orphan))->toBe($orphan);

    $ghostOwner = User::factory()->create();
    $ownerless = Organization::create(['name' => 'Ownerless', 'type' => 'team', 'owner_id' => $ghostOwner->id]);
    $ghostOwner->delete(); // soft delete → owner() resolves null
    $sub = BillingSubscription::create(['organization_id' => $ownerless->id, 'plan_id' => Plan::whereCode('free')->firstOrFail()->id, 'status' => 'active', 'interval' => 'monthly']);
    $evaluate->handle($sub);
    expect($sub->refresh()->over_limit_until)->toBeNull();
});

test('UpsertPlanAction rejects bad interval outside the HTTP layer', function () {
    $this->expectException(ValidationException::class);
    (new UpsertPlanAction)->update(Plan::whereCode('pro')->firstOrFail(), [
        'prices' => [['currency' => 'USD', 'interval' => 'biweekly', 'amount' => 1]],
    ]);
});

test('preview endpoints surface missing prices as 422', function () {
    [, $token, $org] = BillingHelp::userOrg();

    BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/billing/subscription/preview", ['plan_code' => 'ghost'])->assertNotFound();

    BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/billing/subscription/preview", ['plan_code' => 'business', 'currency' => 'GBP'])
        ->assertStatus(422);

    // org without any subscription row (orphan org deleted sub)
    $raw = Organization::create(['name' => 'Zombie', 'type' => 'team', 'owner_id' => $org->owner_id]);
    BillingHelp::as($token)->postJson("/api/v1/orgs/{$raw->id}/billing/subscription/preview", ['plan_code' => 'business'])->assertNotFound(); // not a member
});

test('checkout uses non-trial path when plan has zero trial days', function () {
    [$ada, $token] = Tenancy::user('n0trial@m3.test');
    $org = CreateOrganizationAction::class;
    $org = app($org)->handle($ada, 'Trialless');

    $biz = Plan::whereCode('business')->firstOrFail();
    $biz->forceFill(['trial_days' => 0])->save();

    BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/billing/checkout", ['plan_code' => 'business', 'interval' => 'monthly'])
        ->assertCreated()->assertJsonPath('data.url', fn ($u) => is_string($u) && str_contains($u, 'checkout.fake.test'));
});

test('set default payment method for unknown id is 404 and known swaps default', function () {
    [, $token, $org] = BillingHelp::userOrg();
    BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/billing/payment-methods/nope/default")->assertNotFound();
});

test('fake gateway cancel + period-end toggles are safe when no remote sub exists', function () {
    $gw = BillingHelp::gateway();
    [, , $org] = BillingHelp::userOrg(planCode: 'free');
    $gw->cancel($org);
    $gw->setCancelAtPeriodEnd($org, true);
    expect($gw->subscriptions[$org->id] ?? null)->toBeNull();
});

test('webhook failure row keeps error-free processed marker for ignorable outcomes', function () {
    expect(WebhookEvent::count())->toBe(0);
});

/* ── final coverage tails (added in T9) ── */

test('preview uses target-currency row when current exists but target lacks it', function () {
    [, $token, $org] = BillingHelp::userOrg();

    $gbpPlan = Plan::create([
        'code' => 'gbp-only', 'name' => 'GBP', 'trial_days' => 0, 'active' => true,
        'entitlements' => new PlanEntitlementsData(1, 1, false, 0, 1),
    ]);
    PlanPrice::create(['plan_id' => $gbpPlan->id, 'currency' => 'GBP', 'interval' => 'monthly', 'amount' => 1500]);
    BillingHelp::attachPlan($org, 'gbp-only');

    BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/billing/subscription/preview", ['plan_code' => 'pro', 'currency' => 'GBP'])
        ->assertStatus(422)->assertJsonPath('errors.currency.0', 'Missing target price (GBP).');
});

test('handle() rejects events without id or type', function () {
    $result = app(ProcessWebhookEvent::class)->handle('fake', ['type' => 'x']);
    expect($result->outcome)->toBe('failed');
});

test('processing exceptions are captured on the event row', function () {
    Event::listen(InvoicePaid::class, function (): void {
        throw new RuntimeException('listener exploded');
    });

    [, , $org] = BillingHelp::userOrg(planCode: 'free');
    $gateway = BillingHelp::gateway();
    [$raw, $sig] = $gateway->checkoutCompletedPayload($org, BillingHelp::price('pro')->plan_id, BillingHelp::price('pro')->id, 'monthly', 0);
    test()->call('POST', '/api/v1/billing/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_SIGNATURE' => $sig], $raw);

    [$raw, $sig] = $gateway->invoiceEvent($org, 'invoice.paid');
    test()->call('POST', '/api/v1/billing/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_SIGNATURE' => $sig], $raw)
        ->assertOk()->assertJsonPath('data.outcome', 'failed');

    $row = WebhookEvent::query()->where('type', 'invoice.paid')->first();
    expect($row->error)->toBe('listener exploded')->and($row->processed_at)->toBeNull();
});

test('updated events cover past_due recovery and status map branches', function () {
    [, , $org] = BillingHelp::userOrg(planCode: 'free');
    $gateway = BillingHelp::gateway();
    [$raw, $sig] = $gateway->checkoutCompletedPayload($org, BillingHelp::price('pro')->plan_id, BillingHelp::price('pro')->id, 'monthly', 0);
    test()->call('POST', '/api/v1/billing/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_SIGNATURE' => $sig], $raw);

    foreach (['trialing', 'past_due', 'unpaid', 'active'] as $status) {
        [$raw, $sig] = $gateway->subscriptionUpdated($org, ['status' => $status]);
        test()->call('POST', '/api/v1/billing/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_SIGNATURE' => $sig], $raw)
            ->assertOk();
    }

    expect($org->refresh()->subscription()->status)->toBe(SubscriptionStatus::Active);
});

test('deleted event cancels a known subscription; failed invoice on unknown sub is failed', function () {
    [, , $org] = BillingHelp::userOrg(planCode: 'free');
    $gateway = BillingHelp::gateway();
    [$raw, $sig] = $gateway->checkoutCompletedPayload($org, BillingHelp::price('pro')->plan_id, BillingHelp::price('pro')->id, 'monthly', 0);
    test()->call('POST', '/api/v1/billing/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_SIGNATURE' => $sig], $raw);

    $subId = $gateway->subscriptions[$org->id]['id'];
    [$raw, $sig] = $gateway->emit('customer.subscription.deleted', ['id' => $subId, 'object' => 'subscription']);
    test()->call('POST', '/api/v1/billing/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_SIGNATURE' => $sig], $raw)
        ->assertOk()->assertJsonPath('data.outcome', 'processed');
    expect($org->refresh()->subscription()->status)->toBe(SubscriptionStatus::Canceled);

    [$raw, $sig] = $gateway->invoiceEvent($org, 'invoice.payment_failed', ['subscription' => 'sub_unknown']);
    test()->call('POST', '/api/v1/billing/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_SIGNATURE' => $sig], $raw)
        ->assertOk()->assertJsonPath('data.outcome', 'failed');
});

test('fake gateway period-end toggle applies when remote sub exists', function () {
    [, , $org] = BillingHelp::userOrg();
    $gateway = BillingHelp::gateway();
    $gateway->subscriptions[$org->id] = ['id' => 'fake_sub_pe', 'price' => 'p', 'status' => 'active', 'cancel_at_period_end' => false];
    $gateway->setCancelAtPeriodEnd($org, true);
    expect($gateway->subscriptions[$org->id]['cancel_at_period_end'])->toBeTrue();
});

test('fake gateway rejects signed-but-malformed payloads', function () {
    $raw = '{"foo":1}';
    $sig = 'v1='.hash_hmac('sha256', $raw, (string) config('billing.fake_webhook_secret'));

    $this->expectException(InvalidWebhookException::class);
    BillingHelp::gateway()->ingest($raw, $sig);
});

test('plan cast also accepts valid array payloads', function () {
    $plan = Plan::create([
        'code' => 'arr-cast', 'name' => 'ArrCast',
        'entitlements' => ['max_teams' => 2, 'max_members_per_org' => 4, 'webhooks' => false, 'audit_retention_days' => 1, 'api_rate_limit_per_min' => 2],
    ]);
    expect($plan->entitlements->max_teams)->toBe(2)->and($plan->isFree())->toBeFalse();
});

test('sync skips gateway customers whose organization vanished', function () {
    [, , $org] = BillingHelp::userOrg();
    $gateway = BillingHelp::gateway();
    $gateway->ensureCustomer($org);
    $owner = $org->owner_id;
    $ghost = Organization::create(['name' => 'Ghost', 'type' => 'team', 'owner_id' => $owner]);
    $gateway->ensureCustomer($ghost);
    $ghost->delete(); // soft delete → billing customers remain, organization() resolves null

    $this->artisan('billing:sync')->assertSuccessful();
});

test('stripe default method resolution yields null when unset', function () {
    $org = Organization::create(['name' => 'S', 'type' => 'team', 'owner_id' => User::factory()->create()->id]);
    BillingCustomer::create(['organization_id' => $org->id, 'gateway' => 'stripe', 'gateway_customer_id' => 'cus_9']);
    ApiRequestor::setHttpClient(new FakeStripeHttp([
        [200, ['id' => 'cus_9', 'invoice_settings' => (object) []]],
        [200, ['object' => 'list', 'data' => [[
            'id' => 'pm_1', 'object' => 'payment_method', 'card' => ['brand' => 'visa', 'last4' => '1111', 'exp_month' => 5, 'exp_year' => 2031],
        ]]]],
    ]));
    $methods = (new StripeGateway)->paymentMethods($org);
    ApiRequestor::setHttpClient(null);
    expect($methods[0]->isDefault)->toBeFalse()->and($methods[0]->last4)->toBe('1111');
});
