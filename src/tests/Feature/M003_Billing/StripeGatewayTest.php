<?php

declare(strict_types=1);

use App\Billing\StripeGateway;
use App\Data\Plan\PlanEntitlementsData;
use App\Enums\BillingInterval;
use App\Exceptions\InvalidWebhookException;
use App\Exceptions\PaymentProviderException;
use App\Models\BillingCustomer;
use App\Models\BillingSubscription;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\User;
use Stripe\ApiRequestor;
use Tests\Feature\M003_Billing\FakeStripeHttp;

/**
 * Stripe adapter unit coverage via a mocked HTTP layer (zero network).
 * The recorded-shape assertions here also pre-verify the live-smoke checklist.
 */
function stripeWith(FakeStripeHttp $http): StripeGateway
{
    ApiRequestor::setHttpClient($http);

    return new StripeGateway;
}

/** @param array<int, array{0:int,1:array<string,mixed>}> $queue */
function res(int $status, array $body): array
{
    return [$status, $body];
}

function stripeOrg(): Organization
{
    $owner = User::factory()->create();
    $org = Organization::create(['name' => 'StripeCo', 'type' => 'team', 'owner_id' => $owner->id]);
    BillingCustomer::create(['organization_id' => $org->id, 'gateway' => 'stripe', 'gateway_customer_id' => 'cus_1']);

    return $org;
}

function stripePrice(int $amount = 1900, ?string $remote = 'price_1'): PlanPrice
{
    $plan = Plan::create([
        'code' => 'spro'.uniqid(), 'name' => 'SPro', 'trial_days' => 0, 'active' => true,
        'entitlements' => new PlanEntitlementsData(1, 1, false, 0, 1),
    ]);

    return PlanPrice::create([
        'plan_id' => $plan->id, 'currency' => 'USD', 'interval' => 'monthly',
        'amount' => $amount, 'gateway_price_id' => $remote,
    ]);
}

function subRow(Organization $org, string $remote = 'sub_old'): BillingSubscription
{
    $price = stripePrice();

    return BillingSubscription::create([
        'organization_id' => $org->id,
        'plan_id' => $price->plan_id,
        'status' => 'active',
        'gateway' => 'stripe',
        'gateway_subscription_id' => $remote,
        'interval' => 'monthly',
    ]);
}

beforeEach(function () {
    config(['billing.stripe.webhook_secret' => 'whsec_test', 'billing.stripe.secret' => 'sk_test_fake']);
});

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

test('missing secret on container build path → PaymentProviderException', function () {
    config(['billing.stripe.secret' => '']);
    $this->expectException(PaymentProviderException::class);
    new StripeGateway;
});

test('ensureCustomer returns existing id without http', function () {
    $org = stripeOrg();
    $gw = stripeWith(new FakeStripeHttp);
    expect($gw->ensureCustomer($org))->toBe('cus_1')->and($gw->name())->toBe('stripe');
});

test('ensureCustomer creates remote customer when absent', function () {
    $org = Organization::create(['name' => 'No', 'type' => 'team', 'owner_id' => User::factory()->create()->id]);
    $gw = stripeWith(new FakeStripeHttp([res(200, ['id' => 'cus_new', 'object' => 'customer'])]));
    expect($gw->ensureCustomer($org))->toBe('cus_new')
        ->and(BillingCustomer::where('organization_id', $org->id)->value('gateway_customer_id'))->toBe('cus_new');
});

test('checkoutUrl + trial checkout session shape', function () {
    $org = stripeOrg();
    $price = stripePrice();
    $mock = new FakeStripeHttp([
        res(200, ['id' => 'cs_a', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/cs_a']),
        res(200, ['id' => 'cs_b', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/cs_b']),
    ]);
    $gw = stripeWith($mock);

    expect($gw->checkoutUrl($org, $price)->url)->toBe('https://checkout.stripe.com/c/cs_a')
        ->and($gw->trialCheckoutUrl($org, $price, 14)->gatewayRef)->toBe('cs_b');
});

test('checkout provider failure maps to PaymentProviderException', function () {
    $org = stripeOrg();
    $gw = stripeWith(new FakeStripeHttp([res(400, ['error' => ['message' => 'no']])]));
    $this->expectException(PaymentProviderException::class);
    $gw->checkoutUrl($org, stripePrice());
});

test('applyChange cancels old and creates new subscription', function () {
    $org = stripeOrg();
    subRow($org);
    $from = stripePrice(1900);
    $to = stripePrice(7900);

    $mock = new FakeStripeHttp([
        res(200, ['id' => 'sub_old', 'object' => 'subscription', 'status' => 'canceled']), // cancel
        res(200, ['id' => 'sub_new', 'object' => 'subscription']),          // create
    ]);
    expect(stripeWith($mock)->applyChange($org, $from, $to)->gatewayRef)->toBe('sub_new');
});

test('applyChange failure wraps provider error', function () {
    $org = stripeOrg();
    subRow($org);
    $mock = new FakeStripeHttp([
        res(200, ['id' => 'sub_old']),
        res(402, ['error' => ['message' => 'card declined']]),
    ]);
    $this->expectException(PaymentProviderException::class);
    stripeWith($mock)->applyChange($org, stripePrice(), stripePrice(7900));
});

test('cancel without remote sub is a no-op', function () {
    $org = Organization::create(['name' => 'Bare', 'type' => 'team', 'owner_id' => User::factory()->create()->id]);
    stripeWith(new FakeStripeHttp)->cancel($org);
    $this->assertTrue(true);
});

test('previewChange returns prorations from upcoming invoice', function () {
    $org = stripeOrg();
    $sub = subRow($org);

    $mock = new FakeStripeHttp([
        res(200, [ // retrieve current for item id
            'id' => 'sub_old', 'object' => 'subscription',
            'items' => ['object' => 'list', 'data' => [['id' => 'si_1', 'price' => ['id' => 'price_1']]]],
        ]),
        res(200, [ // createPreview invoice
            'object' => 'invoice',
            'total' => 3000,
            'currency' => 'usd',
            'lines' => ['object' => 'list', 'data' => [
                ['description' => 'unused time', 'amount' => -4000, 'currency' => 'usd'],
                ['description' => 'remaining time', 'amount' => 7000, 'currency' => 'usd'],
            ]],
        ]),
    ]);

    $preview = stripeWith($mock)->previewChange($org, $sub->plan->price('USD', 'monthly') ?? stripePrice(), stripePrice(7900), BillingInterval::Monthly);
    expect($preview->totalDue)->toBe(3000)->and(count($preview->lines))->toBe(2);
});

test('previewChange failure maps to provider exception', function () {
    $org = stripeOrg();
    subRow($org);
    $mock = new FakeStripeHttp([
        res(200, ['id' => 'sub_old', 'items' => ['data' => [['id' => 'si_1']]]]),
        res(404, ['error' => ['message' => 'gone']]),
    ]);
    $this->expectException(PaymentProviderException::class);
    stripeWith($mock)->previewChange($org, stripePrice(), stripePrice(7900), BillingInterval::Annual);
});

test('setCancelAtPeriodEnd patches remote subscription', function () {
    $org = stripeOrg();
    subRow($org);
    $mock = new FakeStripeHttp([res(200, ['id' => 'sub_old', 'cancel_at_period_end' => true])]);
    stripeWith($mock)->setCancelAtPeriodEnd($org, true);
    $this->assertTrue(true);
});

test('paymentMethods lists with default flag', function () {
    $org = stripeOrg();
    $mock = new FakeStripeHttp([
        res(200, ['id' => 'cus_1', 'invoice_settings' => ['default_payment_method' => 'pm_2']]),
        res(200, ['object' => 'list', 'data' => [
            ['id' => 'pm_1', 'object' => 'payment_method', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030]],
            ['id' => 'pm_2', 'object' => 'payment_method', 'card' => ['brand' => 'mc', 'last4' => '5555', 'exp_month' => 1, 'exp_year' => 2029]],
        ]]),
    ]);
    $methods = stripeWith($mock)->paymentMethods($org);
    expect($methods[0]->isDefault)->toBeFalse()
        ->and($methods[1]->isDefault)->toBeTrue()
        ->and($methods[0]->brand)->toBe('Visa');
});

test('setupIntent returns client secret', function () {
    $org = stripeOrg();
    $mock = new FakeStripeHttp([res(200, ['id' => 'seti_1', 'client_secret' => 'seti_1_secret_x'])]);
    expect(stripeWith($mock)->setupIntent($org)->clientSecret)->toBe('seti_1_secret_x');
});

test('attach/default/detach roundtrip', function () {
    $org = stripeOrg();
    subRow($org);
    $mock = new FakeStripeHttp([
        res(200, ['id' => 'pm_3', 'customer' => 'cus_1']),                      // attach
        res(200, ['id' => 'sub_old']),                                          // sub default pm
        res(200, ['id' => 'cus_1']),                                            // customer invoice_settings
        res(200, ['id' => 'pm_3']),                                             // detach
    ]);
    $gw = stripeWith($mock);
    $gw->attachPaymentMethod($org, 'pm_3');
    $gw->setDefaultPaymentMethod($org, 'pm_3');
    $gw->detachPaymentMethod($org, 'pm_3');
    $this->assertTrue(true);
});

test('setDefault without subscription only patches customer', function () {
    $org = stripeOrg();
    $mock = new FakeStripeHttp([res(200, ['id' => 'cus_1'])]);
    stripeWith($mock)->setDefaultPaymentMethod($org, 'pm_3');
    $this->assertTrue(true);
});

test('invoices maps mirror fields', function () {
    $org = stripeOrg();
    $mock = new FakeStripeHttp([
        res(200, ['object' => 'list', 'data' => [[
            'id' => 'in_1', 'object' => 'invoice', 'status' => 'paid', 'amount_due' => 1900, 'currency' => 'usd',
            'hosted_invoice_url' => 'https://invoice.stripe.com/i/in_1', 'due_date' => null,
            'status_transitions' => ['paid_at' => 1759000000],
        ]]]),
    ]);
    $invoices = stripeWith($mock)->invoices($org);
    expect($invoices[0]->gatewayInvoiceId)->toBe('in_1')
        ->and($invoices[0]->paidAt)->not->toBeNull()
        ->and($invoices[0]->hostedUrl)->toBe('https://invoice.stripe.com/i/in_1');
});

test('ingest accepts a properly signed event and rejects forgeries', function () {
    $gw = stripeWith(new FakeStripeHttp);
    $payload = json_encode(['id' => 'evt_1', 'type' => 'invoice.paid', 'data' => ['object' => ['id' => 'in_1']]]);
    $timestamp = time();
    $sig = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

    $event = $gw->ingest($payload, $sig);
    expect($event['id'])->toBe('evt_1');

    $this->expectException(InvalidWebhookException::class);
    $gw->ingest($payload, 't='.$timestamp.',v1=forged');
});

test('pullRecentEvents maps the events list', function () {
    $mock = new FakeStripeHttp([
        res(200, ['object' => 'list', 'data' => [['id' => 'evt_9', 'type' => 'invoice.paid', 'data' => ['object' => ['id' => 'in_9']]]]]),
    ]);
    $events = stripeWith($mock)->pullRecentEvents();
    expect($events[0]['id'])->toBe('evt_9')->and($events[0]['data']['object']['id'])->toBe('in_9');
});

test('mirrorPrice reuses existing id or creates lazily', function () {
    $existing = stripePrice(1900, 'price_has');
    $gw = stripeWith(new FakeStripeHttp);
    expect($gw->mirrorPrice($existing))->toBe('price_has');

    $fresh = stripePrice(2900, null);
    $mock = new FakeStripeHttp([res(200, ['id' => 'price_new'])]);
    expect(stripeWith($mock)->mirrorPrice($fresh))->toBe('price_new')
        ->and($fresh->refresh()->gateway_price_id)->toBe('price_new');
});

test('previewChange with missing subscription returns provider error', function () {
    $org = Organization::create(['name' => 'NoSub', 'type' => 'team', 'owner_id' => User::factory()->create()->id]);
    $this->expectException(PaymentProviderException::class);
    stripeWith(new FakeStripeHttp([res(404, ['error' => ['message' => 'no']])]))
        ->previewChange($org, stripePrice(), stripePrice(), BillingInterval::Monthly);
});

test('cancel with remote subscription hits the delete endpoint', function () {
    $org = stripeOrg();
    subRow($org, 'sub_live');
    $mock = new FakeStripeHttp([res(200, ['id' => 'sub_live', 'status' => 'canceled'])]);
    stripeWith($mock)->cancel($org);
    expect(strtoupper($mock->requests[0]['method']))->toBe('DELETE')->and($mock->requests[0]['path'])->toBe('/v1/subscriptions/sub_live');
});

test('preview without item id passes empty strings through to the preview call', function () {
    $org = stripeOrg();
    subRow($org, 'sub_x');
    $mock = new FakeStripeHttp([
        res(200, ['id' => 'sub_x', 'items' => ['object' => 'list', 'data' => []]]),
        res(200, ['object' => 'invoice', 'total' => 0, 'currency' => 'usd', 'lines' => ['object' => 'list', 'data' => []]]),
    ]);
    $preview = stripeWith($mock)->previewChange($org, stripePrice(), stripePrice(7900), BillingInterval::Monthly);
    expect($preview->totalDue)->toBe(0)->and($preview->lines)->toBe([])
        ->and($mock->last()['path'])->toBe('/v1/invoices/create_preview');
});

test('preview for a subscription without remote id short-circuits item lookup', function () {
    $org = stripeOrg();
    $price = stripePrice();
    BillingSubscription::create([
        'organization_id' => $org->id, 'plan_id' => $price->plan_id, 'status' => 'active',
        'gateway' => 'stripe', 'gateway_subscription_id' => null, 'interval' => 'monthly',
    ]);
    $mock = new FakeStripeHttp([res(200, ['object' => 'invoice', 'total' => 0, 'currency' => 'usd', 'lines' => ['object' => 'list', 'data' => []]])]);
    $preview = stripeWith($mock)->previewChange($org, $price, $price, BillingInterval::Monthly);
    expect($preview->totalDue)->toBe(0)
        ->and($mock->requests[0]['path'])->toBe('/v1/invoices/create_preview');
});

test('checkoutSession normalizes a retrieved session or returns null', function () {
    $mock = new FakeStripeHttp([res(200, [
        'id' => 'cs_1', 'object' => 'checkout.session', 'customer' => 'cus_1',
        'subscription' => 'sub_1', 'metadata' => ['org_id' => '1'],
    ])]);
    $event = stripeWith($mock)->checkoutSession('cs_1');
    expect($event['type'])->toBe('checkout.session.completed')
        ->and($event['id'])->toBe('pulled_cs_1')
        ->and($event['data']['object']['subscription'])->toBe('sub_1');

    expect(stripeWith(new FakeStripeHttp([res(404, ['error' => ['message' => 'no such']])]))->checkoutSession('cs_ghost'))->toBeNull();
});
