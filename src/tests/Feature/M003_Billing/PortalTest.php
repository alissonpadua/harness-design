<?php

declare(strict_types=1);

use App\Billing\FakeGateway;
use App\Billing\StripeGateway;
use App\Contracts\Billing\PaymentGateway;
use App\Enums\OrgRole;
use App\Models\BillingInvoice;
use Database\Seeders\PlansSeeder;
use Tests\Feature\M003_Billing\BillingHelp;
use Tests\Support\Tenancy;

beforeEach(function () {
    $this->seed(PlansSeeder::class);
});

/* ─────────────── AC-003.13–.18 portal ─────────────── */

test('AC-003.13 subscription snapshot with over-limit fields', function () {
    [, $token, $org] = BillingHelp::userOrg(planCode: 'free');

    BillingHelp::as($token)->getJson("/api/v1/orgs/{$org->id}/billing/subscription")
        ->assertOk()
        ->assertJsonPath('data.plan_code', 'free')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.is_over_limit', false)
        ->assertJsonStructure(['data' => ['plan_code', 'plan_name', 'status', 'interval', 'currency_default', 'current_period_end', 'trial_end', 'cancel_at_period_end', 'past_due_since', 'over_limit_until', 'is_over_limit', 'entitlements']]);
});

test('AC-003.14/15 preview then change, with same-plan rejection', function () {
    [, $token, $org] = BillingHelp::userOrg(); // pro → business

    BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/billing/subscription/preview", ['plan_code' => 'business'])
        ->assertOk()
        ->assertJsonPath('data.currency', 'USD')
        ->assertJsonCount(2, 'data.lines');

    $preview = BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/billing/subscription/preview", ['plan_code' => 'business'])->json('data');
    expect($preview['lines'][0]['amount'])->toBeLessThan(0)
        ->and($preview['lines'][1]['amount'])->toBeGreaterThan(0);

    BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/billing/subscription/change", ['plan_code' => 'pro'])->assertStatus(422); // same plan
    BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/billing/subscription/change", ['plan_code' => 'ghost'])->assertNotFound();

    BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/billing/subscription/change", ['plan_code' => 'business'])
        ->assertOk()->assertJsonPath('data.changed', true);

    expect($org->refresh()->subscription()->plan->code)->toBe('business');
});

test('AC-003.16 payment methods lifecycle with default-guard', function () {
    [, $token, $org] = BillingHelp::userOrg();

    BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/billing/payment-methods/setup")
        ->assertCreated()->assertJsonStructure(['data' => ['client_secret', 'url']]);

    BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/billing/payment-methods", ['payment_method_id' => 'fake_pm_amex'])
        ->assertOk();

    BillingHelp::as($token)->getJson("/api/v1/orgs/{$org->id}/billing/payment-methods")
        ->assertOk()->assertJsonCount(2, 'data.payment_methods');

    BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/billing/payment-methods/fake_pm_amex/default")->assertOk();

    // removing the default while a paid subscription is active → 422
    BillingHelp::as($token)->deleteJson("/api/v1/orgs/{$org->id}/billing/payment-methods/fake_pm_amex")->assertStatus(422);

    // switch default back, then detach works
    BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/billing/payment-methods/fake_pm_visa/default")->assertOk();
    BillingHelp::as($token)->deleteJson("/api/v1/orgs/{$org->id}/billing/payment-methods/fake_pm_amex")->assertOk();
    BillingHelp::as($token)->deleteJson("/api/v1/orgs/{$org->id}/billing/payment-methods/ghost_pm")->assertNotFound();
});

test('AC-003.17 invoices mirror + download; foreign invoice 404', function () {
    [, $token, $org] = BillingHelp::userOrg();
    $gateway = BillingHelp::gateway();

    [$raw, $sig] = $gateway->invoiceEvent($org, 'invoice.paid');
    test()->call('POST', '/api/v1/billing/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_SIGNATURE' => $sig], $raw);

    BillingHelp::as($token)->getJson("/api/v1/orgs/{$org->id}/billing/invoices")
        ->assertOk()->assertJsonPath('data.invoices.0.status', 'paid');

    $id = BillingInvoice::query()->where('organization_id', $org->id)->value('id');
    BillingHelp::as($token)->getJson("/api/v1/orgs/{$org->id}/billing/invoices/{$id}/download")
        ->assertOk()->assertJsonStructure(['data' => ['url']]);

    BillingHelp::as($token)->getJson("/api/v1/orgs/{$org->id}/billing/invoices/999999/download")->assertNotFound();
});

test('AC-003.18 cancel-at-period-end and resume', function () {
    [, $token, $org] = BillingHelp::userOrg();

    BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/billing/subscription/cancel", ['cancel' => true])
        ->assertOk()->assertJsonPath('data.cancel_at_period_end', true);
    BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/billing/subscription/cancel", ['cancel' => true])->assertStatus(422); // already scheduled
    BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/billing/subscription/cancel", ['cancel' => false])
        ->assertOk();

    // free plan has nothing to cancel
    BillingHelp::attachPlan($org, 'free');
    BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/billing/subscription/cancel", ['cancel' => true])->assertStatus(422);
});

test('portal reads respect org scoping and role matrix', function () {
    [$ada, $adaToken, $org] = BillingHelp::userOrg();
    [$bob, $bobToken] = Tenancy::user('bob@m3.test');

    BillingHelp::as($bobToken)->getJson("/api/v1/orgs/{$org->id}/billing/subscription")->assertNotFound();

    Tenancy::addMember($org, $bob, role: OrgRole::Member);
    BillingHelp::as($bobToken)->getJson("/api/v1/orgs/{$org->id}/billing/subscription")->assertForbidden();
    unset($ada, $adaToken);
});

test('AC-003.23 StripeGateway compiles + contract parity surface', function () {
    $methods = get_class_methods(FakeGateway::class);
    expect($methods)->toContain('ingest')->toContain('mirrorPrice');

    $rc = new ReflectionClass(StripeGateway::class);
    foreach ((new ReflectionClass(PaymentGateway::class))->getMethods() as $m) {
        expect($rc->hasMethod($m->getName()))->toBeTrue("StripeGateway::{$m->getName()} missing");
    }
});
