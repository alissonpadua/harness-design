<?php

declare(strict_types=1);

namespace Tests\Feature\M005_AdminOps;

use App\Actions\Admin\SetOrgEntitlementOverrideAction;
use App\Actions\Billing\EnsureOrgSubscription;
use App\Actions\Org\CreatePersonalWorkspaceAction;
use App\Actions\Org\UpdateMemberRoleAction;
use App\Enums\OrgRole;
use App\Enums\SubscriptionStatus;
use App\Health\QueueRoundtripHealthCheck;
use App\Health\RedisHealthCheck;
use App\Http\Middleware\HorizonGate;
use App\Models\AuthLink;
use App\Models\BillingSubscription;
use App\Models\NotificationPreference;
use App\Models\OauthAccount;
use App\Models\Organization;
use App\Models\OrganizationEntitlementOverride;
use App\Models\Plan;
use App\Models\User;
use App\Models\WebhookEvent;
use Database\Seeders\PlansSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use LaravelWebauthn\Models\WebauthnKey;
use Mockery;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Spatie\Health\Enums\Status;
use Symfony\Component\HttpFoundation\InputBag;
use Tests\Feature\M003_Billing\BillingHelp;
use Tests\Support\Tenancy;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    $this->seed(PlansSeeder::class);
});

/** @return array{0: User, 1: string} */
function covAdmin(): array
{
    $root = User::factory()->create(['email' => 'root@cov.test']);
    $root->assignRole('super-admin');

    return [$root, $root->createToken('cli', ['*'])->plainTextToken];
}

function covAs(string $token)
{
    test()->flushHeaders();
    app('auth')->forgetGuards();

    return test()->withToken($token);
}

function covSuspend(string $rtToken, int $userId, string $reason): void
{
    covAs($rtToken)->postJson("/admin/v1/users/{$userId}/suspend", ['reason' => $reason])->assertOk();
}

/* ── suspended-account gates on every credential lane ── */

test('suspended users blocked at passkey, magic-link and oauth lanes', function () {
    [, $rt] = covAdmin();

    // passkey lane
    [$ada] = Tenancy::user();
    $key = new WebauthnKey([
        'user_id' => $ada->id, 'name' => 'k', 'credentialId' => 'cov-bytes', // mutator base64url-encodes on write
        'type' => 'public-key', 'transports' => '[]', 'attestationType' => 'none', 'trustPath' => '[]',
        'aaguid' => '00000000-0000-0000-0000-000000000000', 'credentialPublicKey' => 'AA', 'counter' => 0,
    ]);
    $key->save();
    covSuspend($rt, $ada->id, 'passkey hold');

    $rawId = Base64UrlSafe::encodeUnpadded('cov-bytes');
    $this->postJson('/api/v1/auth/passkeys/authenticate', [
        'credential' => ['id' => $rawId, 'rawId' => $rawId, 'type' => 'public-key', 'response' => ['clientDataJSON' => 'e30']],
        'device_type' => 'web',
    ])->assertStatus(403)->assertJsonPath('message', 'Account suspended.');

    // magic-link lane
    $second = User::factory()->create(['email' => 'mag@cov.test']);
    $link = AuthLink::issue($second, 'magic_link');
    $rawToken = $link->token;
    covSuspend($rt, $second->id, 'magic hold');
    $this->postJson('/api/v1/auth/magic-link/consume', ['token' => $rawToken, 'device_type' => 'web'])
        ->assertStatus(403)->assertJsonPath('message', 'Account suspended.');

    // oauth lane (existing user matched by provider id)
    $third = User::factory()->create(['email' => 'oa@cov.test']);
    OauthAccount::create(['user_id' => $third->id, 'provider' => 'google', 'provider_id' => 'g-cov', 'provider_email' => 'oa@cov.test']);
    covSuspend($rt, $third->id, 'oauth hold');

    $social = Mockery::mock(SocialiteUser::class);
    $social->shouldReceive('getId')->andReturn('g-cov');
    $social->shouldReceive('getEmail')->andReturn('oa@cov.test');
    $social->shouldReceive('getName')->andReturn('OA');
    $social->shouldReceive('getRaw')->andReturn(['email' => 'oa@cov.test', 'email_verified' => true]);
    $driver = Mockery::mock(AbstractProvider::class);
    $driver->shouldReceive('stateless')->andReturnSelf()->shouldReceive('user')->andReturn($social);
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

    $this->postJson('/api/v1/auth/oauth/google/exchange', ['code' => 'code-123', 'device_type' => 'web'])
        ->assertStatus(403)->assertJsonPath('message', 'Account suspended.');
});

/* ── force-delete personal-workspace loop ── */

test('force-delete removes personal workspace and per-user rows', function () {
    [, $rt] = covAdmin();
    $u = User::factory()->create(['email' => 'pers@cov.test', 'email_verified_at' => now()]);
    $personal = app(CreatePersonalWorkspaceAction::class)->handle($u);
    $u->createToken('cli', ['*']);
    DatabaseNotification::create([
        'id' => (string) Str::uuid(), 'type' => 'x',
        'notifiable_type' => User::class, 'notifiable_id' => $u->id, 'data' => [],
    ]);
    NotificationPreference::create(['user_id' => $u->id, 'type' => 'billing.invoice_paid', 'email_enabled' => false]);

    covAs($rt)->deleteJson('/admin/v1/users/'.$u->id, ['confirm_text' => 'pers@cov.test'])->assertOk();

    expect(Organization::withTrashed()->find($personal->id))->toBeNull()
        ->and(User::withTrashed()->find($u->id))->toBeNull()
        ->and(NotificationPreference::where('user_id', $u->id)->count())->toBe(0);
});

/* ── override action direct edges ── */

test('override action rejects unknown keys and invalid merges when called directly', function () {
    [$root] = covAdmin();
    [$ada] = Tenancy::user();
    $org = Tenancy::org($ada, 'DirectCo');
    $action = app(SetOrgEntitlementOverrideAction::class);

    expect(fn () => $action->handle($root, $org, ['bogus' => 1]))
        ->toThrow(ValidationException::class);

    expect(fn () => $action->handle($root, $org, ['max_teams' => 'not-a-number']))
        ->toThrow(ValidationException::class, 'Overrides must form a valid complete entitlements shape.');
});

/* ── webhook replay lanes ── */

test('forced replay routes every event arm', function () {
    [, $rt] = covAdmin();

    foreach (['customer.subscription.updated', 'customer.subscription.deleted', 'invoice.paid', 'invoice.payment_failed', 'something.unknown'] as $type) {
        $row = WebhookEvent::create([
            'gateway' => 'fake', 'gateway_event_id' => 'evt_'.uniqid(), 'type' => $type,
            'payload' => ['id' => 'x'.uniqid(), 'type' => $type, 'data' => ['object' => ['id' => 'sub_none', 'subscription' => 'sub_none']]],
            'processed_at' => now(),
        ]);
        covAs($rt)->postJson('/admin/v1/billing/webhooks/'.$row->id.'/replay')->assertOk();
    }

    // handler throws mid-replay -> failed outcome, endpoint still 200
    [$bu] = Tenancy::user();
    $boomOrg = Tenancy::org($bu, 'BoomCo');
    $boom = BillingSubscription::query()->updateOrCreate(
        ['organization_id' => $boomOrg->id],
        ['plan_id' => Plan::query()->where('code', 'pro')->value('id'),
            'gateway' => 'fake', 'gateway_subscription_id' => 'boom', 'status' => SubscriptionStatus::Active],
    );
    $row = WebhookEvent::create([
        'gateway' => 'fake', 'gateway_event_id' => 'evt_boom', 'type' => 'customer.subscription.updated',
        'payload' => ['id' => 'evt_boom', 'type' => 'customer.subscription.updated', 'data' => ['object' => ['id' => 'boom', 'status' => ['bad']]]],
        'processed_at' => now(),
    ]);
    covAs($rt)->postJson('/admin/v1/billing/webhooks/'.$row->id.'/replay')
        ->assertOk()->assertJsonPath('data.outcome', 'failed');
    expect($boom->refresh()->status)->toBe(SubscriptionStatus::Active);
});

/* ── admin_locked skips every late event lane ── */

test('admin_locked skips every late event lane', function () {
    [, $rt] = covAdmin();
    [$ada] = Tenancy::user();
    $org = Tenancy::org($ada, 'LateCo');

    $sub = app(EnsureOrgSubscription::class)->handle($org);
    $sub->forceFill([
        'plan_id' => Plan::query()->where('code', 'pro')->value('id'),
        'status' => SubscriptionStatus::Active,
        'gateway_subscription_id' => 'fake_sub_late',
        'admin_locked' => true,
    ])->save();

    $gateway = BillingHelp::gateway();

    foreach ([
        fn () => $gateway->emit('customer.subscription.deleted', ['id' => 'fake_sub_late', 'object' => 'subscription']),
        fn () => $gateway->invoiceEvent($org, 'invoice.paid', ['subscription' => 'fake_sub_late']),
        fn () => $gateway->invoiceEvent($org, 'invoice.payment_failed', ['subscription' => 'fake_sub_late']),
    ] as $emit) {
        [$raw, $sig] = $emit();
        $this->call('POST', '/api/v1/billing/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_SIGNATURE' => $sig], $raw)
            ->assertOk()->assertJsonPath('data.outcome', 'skipped_locked');
    }

    expect($org->refresh()->subscription()->status)->toBe(SubscriptionStatus::Active);
});

/* ── org audit cursor + override relation ── */

test('org audit paginates with next_cursor and override exposes the relation', function () {
    [, $rt] = covAdmin();
    [$ada, $adaToken] = Tenancy::user();
    $org = Tenancy::org($ada, 'PagingCo');

    $org->forceFill(['name' => 'Paged1'])->save();
    $org->forceFill(['name' => 'Paged2'])->save();

    $page = covAs($adaToken)->getJson('/api/v1/orgs/'.$org->id.'/audit?limit=1')->assertOk();
    expect($page->json('data.audit'))->toHaveCount(1)->and($page->json('data.next_cursor'))->not->toBeNull();

    $row = OrganizationEntitlementOverride::create(['organization_id' => $org->id, 'overrides' => ['max_teams' => 9]]);
    expect($row->organization->id)->toBe($org->id);
});

/* ── HorizonGate direct unit branches ── */

test('HorizonGate verifies pass cookies, expiry and signature exceptions', function () {
    $request = Request::create('/horizon', 'GET');
    $request->cookies->set(HorizonGate::PASS_COOKIE, HorizonGate::mintPass());
    expect(HorizonGate::passes($request))->toBeTrue();

    $request2 = Request::create('/horizon', 'GET');
    $request2->cookies->set(HorizonGate::PASS_COOKIE, '1999999999.'.str_repeat('0', 64));
    expect(HorizonGate::passes($request2))->toBeFalse();

    $request3 = Request::create('/horizon', 'GET');
    $request3->cookies->set(HorizonGate::PASS_COOKIE, 'tampered');
    expect(HorizonGate::passes($request3))->toBeFalse();

    $bad = Request::create('/horizon?expires=abc&signature=%F0', 'GET');
    expect(HorizonGate::passes($bad))->toBeFalse();

    // signer blows up mid-verification -> validSignature catch returns false
    $evil = new class extends Request
    {
        public function getSchemeAndHttpHost(): string
        {
            throw new \RuntimeException('boom');
        }
    };
    $evil->query = new InputBag(['expires' => '9999999999', 'signature' => 'x']);
    expect(HorizonGate::passes($evil))->toBeFalse();

    $user = User::factory()->create();
    expect($user->isImpersonating('not-a-token'))->toBeFalse();
    expect($user->impersonationTokens()->count())->toBe(0);
});

/* ── health check failure branches ── */

test('queue + redis health checks report failures', function () {
    config(['queue.default' => 'this-driver-does-not-exist']);
    expect((new QueueRoundtripHealthCheck)->run()->status === Status::ok())->toBeFalse();

    config(['queue.default' => 'null']);
    $started = microtime(true);
    expect((new QueueRoundtripHealthCheck)->run()->status === Status::ok())->toBeFalse()
        ->and(microtime(true) - $started)->toBeLessThan(6.0);

    config([
        'database.redis.client' => 'predis',
        'database.redis.options' => ['prefix' => 'x:', 'client' => 'predis'],
        'database.redis.clusters.default' => ['seeds' => [['host' => 'no-such-host.invalid', 'port' => 1]]],
    ]);
    Redis::purge('default');
    expect((new RedisHealthCheck)->run()->status === Status::ok())->toBeFalse();
});

/* ── user admin show endpoint ── */

test('admin user show returns profile, tokens and recent audit', function () {
    [, $rt] = covAdmin();
    [$ada] = Tenancy::user();

    $res = covAs($rt)->getJson('/admin/v1/users/'.$ada->id)->assertOk();
    expect($res->json('data.email'))->toBe('ada@example.com')
        ->and($res->json('data.tokens'))->toBeArray();

    covAs($rt)->getJson('/admin/v1/users/999999')->assertNotFound();
});

/* ── owner-role immutability guard ── */

test('setting a member role to owner is rejected on the role surface', function () {
    [$ada, $adaToken] = Tenancy::user();
    [$bob] = Tenancy::user('bob@cov.test');
    $org = Tenancy::org($ada, 'OwnerGuardCo');
    $org->memberships()->create(['user_id' => $bob->id, 'role' => OrgRole::Admin->value]);

    // FormRequest blocks role=owner at the HTTP edge (in:admin,member); the
    // action guard is defense-in-depth -> exercise it directly.
    expect(fn () => app(UpdateMemberRoleAction::class)->handle($org, $bob->id, OrgRole::Owner))
        ->toThrow(ValidationException::class, 'Ownership changes require the transfer-ownership flow.');
});
