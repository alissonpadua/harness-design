<?php

declare(strict_types=1);

namespace Tests\Feature\M006_Security;

use App\Actions\Admin\SetOrgEntitlementOverrideAction;
use App\Actions\Org\CreateOrganizationAction;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Tenancy;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    $this->seed(PlansSeeder::class);
});

function admin(): array
{
    $root = User::factory()->create(['email' => 'root@m006.test']);
    $root->assignRole('super-admin');

    return [$root, $root->createToken('cli', ['*'])->plainTextToken];
}

function throttleHeaders($res): void
{
    $res->assertStatus(429)
        ->assertJsonPath('message', 'Too Many Requests');
    $ra = $res->headers->get('Retry-After');
    expect($ra)->not->toBeNull()->and((int) $ra)->toBeGreaterThanOrEqual(1);
}

/* ──────────── AC-006.4 tight auth buckets → 429 + Retry-After ────────────── */

test('AC-006.4 auth-login exhausts at 5/min per ip', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/login', ['email' => 'x@y.test', 'password' => 'whatever', 'device_type' => 'web'])
            ->assertStatus(401);
    }
    throttleHeaders($this->postJson('/api/v1/auth/login', ['email' => 'x@y.test', 'password' => 'whatever', 'device_type' => 'web']));
});

test('AC-006.4 auth-forgot exhausts at 5/min per ip', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@y.test'])->assertAccepted();
    }
    throttleHeaders($this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@y.test']));
});

test('AC-006.4 auth-reset exhausts at 10/min per ip', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/auth/reset-password', ['token' => str_repeat('a', 64), 'email' => 'nobody@y.test', 'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd'])
            ->assertStatus(422);
    }
    throttleHeaders($this->postJson('/api/v1/auth/reset-password', ['token' => str_repeat('a', 64), 'email' => 'nobody@y.test', 'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd']));
});

test('AC-006.4 auth-2fa bucket keyed per user exhausts at 10/min', function () {
    [, $token] = Tenancy::user();
    for ($i = 0; $i < 10; $i++) {
        $this->withToken($token)->postJson('/api/v1/auth/2fa/enroll')->assertOk();
    }
    $this->flushHeaders();
    throttleHeaders($this->withToken($token)->postJson('/api/v1/auth/2fa/enroll'));
});

/* ───────────── AC-006.5 plan api_rate_limit_per_min (per-org) ─────────────── */

test('AC-006.5 org throttle follows plan entitlement and 429s the org', function () {
    [$root] = admin();
    [$ada, $adaToken] = Tenancy::user();
    $org = Tenancy::org($ada, 'LimitedCo');
    app(SetOrgEntitlementOverrideAction::class)->handle($root, $org, ['api_rate_limit_per_min' => 3]);
    Cache::forget('plan-rl:'.$org->id);

    for ($i = 0; $i < 3; $i++) {
        $this->withToken($adaToken)->getJson('/api/v1/orgs/'.$org->id)->assertOk();
    }
    throttleHeaders($this->withToken($adaToken)->getJson('/api/v1/orgs/'.$org->id));
});

test('AC-006.5 second org keeps its own budget (isolation)', function () {
    [$root] = admin();
    [$ada, $adaToken] = Tenancy::user();
    $a = Tenancy::org($ada, 'OrgA');
    $b = app(CreateOrganizationAction::class)->handle($ada, 'OrgB');
    app(SetOrgEntitlementOverrideAction::class)->handle($root, $a, ['api_rate_limit_per_min' => 2]);
    app(SetOrgEntitlementOverrideAction::class)->handle($root, $b, ['api_rate_limit_per_min' => 8]);
    Cache::forget('plan-rl:'.$a->id);
    Cache::forget('plan-rl:'.$b->id);

    $this->withToken($adaToken)->getJson('/api/v1/orgs/'.$a->id)->assertOk();
    $this->withToken($adaToken)->getJson('/api/v1/orgs/'.$a->id)->assertOk();
    throttleHeaders($this->withToken($adaToken)->getJson('/api/v1/orgs/'.$a->id));

    // org B budget intact despite A being throttled
    $this->withToken($adaToken)->getJson('/api/v1/orgs/'.$b->id)->assertOk();
});

test('AC-006.5 raising the override lifts the ceiling', function () {
    [$root] = admin();
    [$ada, $adaToken] = Tenancy::user();
    $org = Tenancy::org($ada, 'RaisedCo');
    $set = app(SetOrgEntitlementOverrideAction::class);
    $set->handle($root, $org, ['api_rate_limit_per_min' => 1]);
    Cache::forget('plan-rl:'.$org->id);

    $this->withToken($adaToken)->getJson('/api/v1/orgs/'.$org->id)->assertOk();
    throttleHeaders($this->withToken($adaToken)->getJson('/api/v1/orgs/'.$org->id));

    $set->handle($root, $org, ['api_rate_limit_per_min' => 5]);
    Cache::forget('plan-rl:'.$org->id); // plan cache invalidation on override (T2 GREEN wires this too)

    $this->withToken($adaToken)->getJson('/api/v1/orgs/'.$org->id)->assertOk();
    $this->withToken($adaToken)->getJson('/api/v1/orgs/'.$org->id)->assertOk();
});

test('AC-006.5 acting org resolves from the {organization} slug, not the current org', function () {
    [$root] = admin();
    [$ada, $adaToken] = Tenancy::user();
    $here = Tenancy::org($ada, 'HereCo');
    $there = app(CreateOrganizationAction::class)->handle($ada, 'ThereCo');
    app(SetOrgEntitlementOverrideAction::class)->handle($root, $there, ['api_rate_limit_per_min' => 1]);
    Cache::forget('plan-rl:'.$there->id);
    Cache::forget('plan-rl:'.$here->id);

    // ada's CURRENT org is HereCo; hitting ThereCo by SLUG must consume ThereCo's budget
    $this->withToken($adaToken)->getJson('/api/v1/orgs/'.$there->slug)->assertOk();
    throttleHeaders($this->withToken($adaToken)->getJson('/api/v1/orgs/'.$there->slug));

    // HereCo untouched
    $this->withToken($adaToken)->getJson('/api/v1/orgs/'.$here->id)->assertOk();
});
