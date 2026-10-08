<?php

declare(strict_types=1);

namespace Tests\Feature\M005_AdminOps;

use App\Http\Middleware\HorizonGate;
use App\Models\User;
use App\Models\WebhookEvent;
use Database\Seeders\PlansSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Spatie\Health\Facades\Health;
use Tests\Feature\M003_Billing\BillingHelp;
use Tests\Support\Tenancy;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    $this->seed(PlansSeeder::class);
});

function opsToken(): string
{
    $root = User::factory()->create(['email' => 'root@ops.test']);
    $root->assignRole('super-admin');

    return $root->createToken('cli', ['*'])->plainTextToken;
}

function opsAs(string $token)
{
    test()->flushHeaders();
    app('auth')->forgetGuards();

    return test()->withToken($token);
}

test('AC-005.9 /up public health reports db, redis, queue', function () {
    $res = $this->getJson('/up')->assertOk();

    expect($res->json('status'))->toBe('ok')
        ->and(collect($res->json('checks'))->pluck('name')->all())
        ->toBe(['DatabaseCheck', 'RedisHealthCheck', 'QueueRoundtripHealthCheck']);
});

test('AC-005.9 /up flips to 503 when a check fails', function () {
    $failing = new class extends Check
    {
        public function run(): Result
        {
            return Result::make()->failed('synthetic');
        }
    };

    Health::clearChecks()->checks([$failing]);

    $res = $this->getJson('/up')->assertStatus(503);
    expect($res->json('status'))->toBe('problem')->and($res->json('checks.0.status'))->toBe('problem');
});

test('AC-005.10 horizon: 403 anon/plain, bearer super-admin ok, signed entry mints pass, impersonated barred', function () {
    $rt = opsToken();

    $this->flushHeaders();
    app('auth')->forgetGuards();
    $this->getJson('/horizon')->assertForbidden();

    $plain = User::factory()->create(['email' => 'joe@ops.test']);
    opsAs($plain->createToken('cli', ['*'])->plainTextToken)->get('/horizon')->assertForbidden();

    opsAs($rt)->get('/horizon')->assertOk();

    $url = opsAs($rt)->getJson('/admin/v1/ops/horizon-url')->assertOk()->json('data.url');
    expect($url)->toMatch('/horizon\?expires=\d+&signature=[a-f0-9]+$/');

    // garbage signature without a pass must fail (no bearer, fresh guards)
    $this->flushHeaders();
    app('auth')->forgetGuards();
    $this->get('/horizon?expires=9999999999&signature=deadbeef')->assertForbidden();

    $entry = $this->get($url)->assertOk();
    $entry->assertCookie(HorizonGate::PASS_COOKIE);

    // impersonated admin sessions cannot even mint
    [$ada] = Tenancy::user();
    $imp = opsAs($rt)->postJson('/admin/v1/users/'.$ada->id.'/impersonate')->json('data.token');
    opsAs($imp)->getJson('/admin/v1/ops/horizon-url')->assertForbidden();

    expect(DB::table('activity_log')->where('event', 'horizon_link')->count())->toBe(1);
});

test('AC-005.13 webhook replay: duplicate normally, forced re-run is idempotent + audited', function () {
    $rt = opsToken();
    [$ada, $adaToken] = Tenancy::user();
    $org = Tenancy::org($ada, 'ReplayCo');

    $gateway = BillingHelp::gateway();
    [$raw, $sig] = $gateway->checkoutCompletedPayload($org, BillingHelp::price('pro')->plan_id, BillingHelp::price('pro')->id, 'monthly', 0);
    $this->call('POST', '/api/v1/billing/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_SIGNATURE' => $sig], $raw)->assertOk();

    $row = WebhookEvent::firstOrFail();

    // duplicate via normal path
    $this->call('POST', '/api/v1/billing/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_SIGNATURE' => $sig], $raw)
        ->assertOk()->assertJsonPath('data.outcome', 'duplicate');

    // forced replay re-runs handlers
    opsAs($rt)->postJson('/admin/v1/billing/webhooks/'.$row->id.'/replay')->assertOk()
        ->assertJsonPath('data.outcome', 'processed');

    expect(WebhookEvent::count())->toBe(1)
        ->and($org->refresh()->subscription()->plan->code)->toBe('pro')
        ->and(DB::table('activity_log')->where('event', 'webhook_replay')->count())->toBe(1);

    opsAs($rt)->postJson('/admin/v1/billing/webhooks/99999/replay')->assertNotFound();
    unset($adaToken);
});

function BillingHelpPlan(): int
{
    return BillingHelp::price('pro')->plan_id;
}
