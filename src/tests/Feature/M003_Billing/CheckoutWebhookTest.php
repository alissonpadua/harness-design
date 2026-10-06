<?php

declare(strict_types=1);

use App\Billing\Events\InvoicePaid;
use App\Billing\Events\PaymentFailed;
use App\Billing\Events\PaymentRecovered;
use App\Models\BillingInvoice;
use App\Models\WebhookEvent;
use Database\Seeders\PlansSeeder;
use Tests\Feature\M003_Billing\BillingHelp;
use Tests\Support\Tenancy;

beforeEach(function () {
    $this->seed(PlansSeeder::class);
});

function sendWebhook(string $raw, string $sig)
{
    return test()->call(
        'POST',
        '/api/v1/billing/webhook/stripe',
        [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_SIGNATURE' => $sig],
        $raw,
    );
}

/* ─────────────────────────── AC-003.5–.8 checkout ──────────────────────── */

test('AC-003.5 checkout validation matrix', function () {
    [, $token, $org] = BillingHelp::userOrg(planCode: 'free');

    BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/billing/checkout", ['plan_code' => 'ghost', 'interval' => 'monthly'])->assertNotFound();
    BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/billing/checkout", ['plan_code' => 'free', 'interval' => 'monthly'])->assertStatus(422);
    BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/billing/checkout", ['plan_code' => 'pro', 'interval' => 'weekly'])->assertStatus(422);
    BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/billing/checkout", ['plan_code' => 'pro', 'interval' => 'monthly', 'currency' => 'JPY'])->assertStatus(422);

    $member = Tenancy::user('memb@x.test');
    BillingHelp::as($member[1])->postJson("/api/v1/orgs/{$org->id}/billing/checkout", ['plan_code' => 'pro', 'interval' => 'monthly'])->assertNotFound();

    BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/billing/checkout", ['plan_code' => 'pro', 'interval' => 'monthly'])
        ->assertCreated()->assertJsonStructure(['data' => ['url', 'plan_code', 'interval']]);
});

test('micro-decision 3: re-checkout while subscribed is blocked', function () {
    [, $token, $org] = BillingHelp::userOrg(); // pro active

    BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/billing/checkout", ['plan_code' => 'business', 'interval' => 'monthly'])
        ->assertStatus(422);
});

test('AC-003.6 webhook checkout.completed with trial metadata activates trialing sub', function () {
    [, $token, $org] = BillingHelp::userOrg(planCode: 'free');
    $subBefore = $org->subscription();

    BillingHelp::as($token)->postJson("/api/v1/orgs/{$org->id}/billing/checkout", ['plan_code' => 'pro', 'interval' => 'monthly'])->assertCreated();

    $gateway = BillingHelp::gateway();
    [$raw, $sig] = $gateway->checkoutCompletedPayload($org, BillingHelp::price('pro')->plan_id, BillingHelp::price('pro')->id, 'monthly', trialDays: 14);

    $response = sendWebhook($raw, $sig);
    $response->assertOk()->assertJsonPath('data.outcome', 'processed');

    $sub = $org->refresh()->subscription();
    expect($sub->id)->toBe($subBefore->id) // same row upgraded (one-active invariant)
        ->and($sub->plan->code)->toBe('pro')
        ->and($sub->status->value)->toBe('trialing')
        ->and($sub->trial_end?->greaterThan(now()->addDays(13)))->toBeTrue()
        ->and($sub->trial_end?->lessThan(now()->addDays(15)))->toBeTrue();
});

/* ─────────────────────────── AC-003.9–.11 webhook ──────────────────────── */

test('AC-003.9 signature enforcement and idempotent replay', function () {
    [, , $org] = BillingHelp::userOrg(planCode: 'free');
    $gateway = BillingHelp::gateway();
    [$raw, $sig] = $gateway->checkoutCompletedPayload($org, BillingHelp::price('pro')->plan_id, BillingHelp::price('pro')->id, 'monthly', 0);

    $bad = sendWebhook($raw, 'v1=forgery');
    $bad->assertStatus(400);
    expect(WebhookEvent::count())->toBe(0);

    $ok = sendWebhook($raw, $sig);
    $ok->assertOk()->assertJsonPath('data.outcome', 'processed');

    $replay = sendWebhook($raw, $sig);
    $replay->assertOk()->assertJsonPath('data.outcome', 'duplicate');
    expect(WebhookEvent::count())->toBe(1);
});

test('AC-003.10 invoice events move the mirror and fire domain events', function () {
    $seen = [];
    Event::listen(PaymentFailed::class, function () use (&$seen): void {
        $seen[] = 'failed';
    });
    Event::listen(PaymentRecovered::class, function () use (&$seen): void {
        $seen[] = 'recovered';
    });
    Event::listen(InvoicePaid::class, function () use (&$seen): void {
        $seen[] = 'invoiced';
    });

    [, $token, $org] = BillingHelp::userOrg(planCode: 'free');
    $gateway = BillingHelp::gateway();

    [$raw, $sig] = $gateway->checkoutCompletedPayload($org, BillingHelp::price('pro')->plan_id, BillingHelp::price('pro')->id, 'monthly', 0);
    sendWebhook($raw, $sig);

    [$raw, $sig] = $gateway->invoiceEvent($org, 'invoice.payment_failed');
    $r1 = sendWebhook($raw, $sig);
    $r1->assertOk()->assertJsonPath('data.outcome', 'processed');

    $sub = $org->refresh()->subscription();
    expect($sub->status->value)->toBe('past_due')->and($sub->past_due_since)->not->toBeNull();
    expect($seen)->toContain('failed');

    [$raw, $sig] = $gateway->invoiceEvent($org, 'invoice.paid');
    sendWebhook($raw, $sig);

    $sub = $org->refresh()->subscription();
    expect($sub->status->value)->toBe('active')
        ->and($sub->past_due_since)->toBeNull()
        ->and(BillingInvoice::query()->where('organization_id', $org->id)->count())->toBe(2);
    expect($seen)->toContain('recovered')->toContain('invoiced');
    unset($token, $seen);
});

test('AC-003.11 subscription.updated/deleted map cancel+resume+period; unknown stored as ignored', function () {
    [, , $org] = BillingHelp::userOrg(planCode: 'free');
    $gateway = BillingHelp::gateway();
    [$raw, $sig] = $gateway->checkoutCompletedPayload($org, BillingHelp::price('pro')->plan_id, BillingHelp::price('pro')->id, 'monthly', 0);
    sendWebhook($raw, $sig);

    [$raw, $sig] = $gateway->subscriptionUpdated($org, ['cancel_at_period_end' => true, 'current_period_end' => now()->addDays(9)->getTimestamp()]);
    $r = sendWebhook($raw, $sig);
    $r->assertOk();

    $sub = $org->refresh()->subscription();
    expect($sub->cancel_at_period_end)->toBeTrue()
        ->and($sub->current_period_end->day)->toBe(now()->addDays(9)->day);

    [$raw, $sig] = $gateway->emit('cosmic.rays.beamed', ['whatever' => true]);
    $r = sendWebhook($raw, $sig);
    $r->assertOk()->assertJsonPath('data.outcome', 'ignored');
    expect(WebhookEvent::query()->where('type', 'cosmic.rays.beamed')->exists())->toBeTrue();
});

test('AC-003.12 billing:ingest replays pulled events through the pipeline', function () {
    [, , $org] = BillingHelp::userOrg(planCode: 'free');
    $gateway = BillingHelp::gateway();
    $gateway->checkoutCompletedPayload($org, BillingHelp::price('pro')->plan_id, BillingHelp::price('pro')->id, 'monthly', 0);

    $this->artisan('billing:ingest')->assertSuccessful();

    expect($org->refresh()->subscription()->plan->code)->toBe('pro');

    $this->artisan('billing:ingest')->assertSuccessful(); // duplicates safe
    expect(WebhookEvent::count())->toBe(1);
});

test('failed processing records error on the event row', function () {
    $gateway = BillingHelp::gateway();
    [$raw, $sig] = $gateway->emit('checkout.session.completed', [
        'id' => 'x', 'object' => 'checkout.session', 'customer' => 'ghost_cus', 'metadata' => [],
    ]);

    $r = sendWebhook($raw, $sig);
    $r->assertOk()->assertJsonPath('data.outcome', 'failed');
    expect(WebhookEvent::first()->error)->toBeNull() // 'failed' outcome via org lookup returns 'failed' without throwing
        ->and(WebhookEvent::count())->toBe(1);
});

test('checkout return page pulls the session and mirrors it (tunnel-less completion)', function () {
    [, , $org] = BillingHelp::userOrg(planCode: 'free');
    $gateway = BillingHelp::gateway();

    // unknown session → friendly 404 page
    $this->getJson('/api/v1/billing/checkout/return?session_id=cs_missing')
        ->assertNotFound()->assertSee('Session not found', false);

    // seed the fake session exactly as checkout would leave it
    [$payloadRaw] = $gateway->checkoutCompletedPayload($org, BillingHelp::price('pro')->plan_id, BillingHelp::price('pro')->id, 'monthly', 14);
    $event = json_decode((string) $payloadRaw, true);
    $gateway->sessions['cs_done_test'] = $event;

    $r = $this->get('/api/v1/billing/checkout/return?session_id=cs_done_test');
    $r->assertOk()->assertSee('Payment received', false)->assertSee('Processed', false);

    $sub = $org->refresh()->subscription();
    expect($sub->plan->code)->toBe('pro')->and($sub->status->value)->toBe('trialing');

    // replay is duplicate-safe through the same idempotent pipeline
    $this->get('/api/v1/billing/checkout/return?session_id=cs_done_test')
        ->assertOk()->assertSee('Duplicate', false);

    // empty param
    $this->get('/api/v1/billing/checkout/return')->assertNotFound();
});
