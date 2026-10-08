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
use LaravelWebauthn\Models\WebauthnKey;
use Tests\Support\Tenancy;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    $this->seed(PlansSeeder::class);
});

function tokAdmin(): array
{
    $root = User::factory()->create(['email' => 'root@tok.test']);
    $root->assignRole('super-admin');

    return [$root, $root->createToken('cli', ['*'])->plainTextToken];
}

function tokAs(string $token)
{
    test()->flushHeaders();
    app('auth')->forgetGuards();

    return test()->withToken($token);
}

function totpedUser(string $email = 'ada@example.com'): array
{
    [$u, $token] = Tenancy::user($email);
    $u->forceFill(['two_factor_confirmed_at' => now()])->save();

    return [$u, $token];
}

/* ───────────── AC-006.6 creation + shown-once + validation ───────────────── */

test('AC-006.6 integration token created once, plaintext never listable, audited', function () {
    [$ada, $adaToken] = totpedUser();
    $org = Tenancy::org($ada, 'TokCo');

    $res = tokAs($adaToken)->postJson('/api/v1/orgs/'.$org->id.'/tokens', [
        'name' => 'ci-runner', 'abilities' => ['members.view'],
    ])->assertCreated();

    $plain = $res->json('data.token');
    expect($plain)->not->toBeEmpty()
        ->and($res->json('data.abilities'))->toBe(['members.view'])
        ->and($res->json('data.name'))->toBe('ci-runner');

    $list = tokAs($adaToken)->getJson('/api/v1/orgs/'.$org->id.'/tokens')->assertOk();
    expect($list->json('data.tokens.0.name'))->toBe('ci-runner')
        ->and($list->json('data.tokens.0.token'))->toBeNull()
        ->and($list->json('data.tokens.0.last_used_at'))->toBeNull();

    // audit row exists
    $audited = (bool) \DB::table('activity_log')->where('event', 'integration_token_created')->exists();
    expect($audited)->toBeTrue();
});

test('AC-006.6 abilities must exist in the permission catalog', function () {
    [$ada, $adaToken] = totpedUser();
    $org = Tenancy::org($ada, 'TokCo2');

    tokAs($adaToken)->postJson('/api/v1/orgs/'.$org->id.'/tokens', [
        'name' => 'bad', 'abilities' => ['members.view', 'not.a.permission'],
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['abilities.1']);
});

test('AC-006.6 device tokens never appear in the integration token list', function () {
    [$ada, $adaToken] = totpedUser();
    $org = Tenancy::org($ada, 'TokCo3');
    $ada->createToken('web', ['*'])->accessToken->forceFill(['device_type' => 'web'])->save();

    $res = tokAs($adaToken)->getJson('/api/v1/orgs/'.$org->id.'/tokens')->assertOk();
    expect($res->json('data.tokens'))->toBeArray()->toHaveCount(0);
});

/* ───────────── AC-006.7 2FA gate (Q2=C + Q3=A) ───────────────────────────── */

test('AC-006.7 no second factor -> creation 403; TOTP or passkey unlocks it', function () {
    [$ada, $adaToken] = Tenancy::user();
    $org = Tenancy::org($ada, 'GateCo');

    tokAs($adaToken)->postJson('/api/v1/orgs/'.$org->id.'/tokens', ['name' => 'x', 'abilities' => ['members.view']])
        ->assertStatus(403)
        ->assertJsonPath('message', 'Two-factor authentication is required to create integration tokens.');

    // TOTP lane
    $ada->forceFill(['two_factor_confirmed_at' => now()])->save();
    tokAs($adaToken)->postJson('/api/v1/orgs/'.$org->id.'/tokens', ['name' => 'x', 'abilities' => ['members.view']])
        ->assertCreated();

    // passkey-only lane (fresh user, confirmed 2FA flag NOT set)
    [$bob, $bobToken] = Tenancy::user('bob@gate.test');
    $borg = Tenancy::org($bob, 'GateBco');
    $key = new WebauthnKey([
        'user_id' => $bob->id, 'name' => 'k', 'credentialId' => 'gatebytes', 'type' => 'public-key',
        'transports' => '[]', 'attestationType' => 'none', 'trustPath' => '[]',
        'aaguid' => '00000000-0000-0000-0000-000000000000', 'credentialPublicKey' => 'AA', 'counter' => 0,
    ]);
    $key->save();
    expect($bob->refresh()->two_factor_confirmed_at)->toBeNull();
    tokAs($bobToken)->postJson('/api/v1/orgs/'.$borg->id.'/tokens', ['name' => 'p', 'abilities' => ['members.view']])
        ->assertCreated();
});

/* ───────────── AC-006.6 ability enforcement + revoke (S3 gate middleware) ─── */

test('AC-006.6 integration bearer works only within granted abilities', function () {
    [$ada, $adaToken] = totpedUser();
    $org = Tenancy::org($ada, 'ScopeCo');
    [$bob] = Tenancy::user('bob@scope.test');
    $org->memberships()->create(['user_id' => $bob->id, 'role' => 'member']);

    $mv = tokAs($adaToken)->postJson('/api/v1/orgs/'.$org->id.'/tokens', ['name' => 'mv', 'abilities' => ['members.view']])->assertCreated()->json('data.token');
    $noth = tokAs($adaToken)->postJson('/api/v1/orgs/'.$org->id.'/tokens', ['name' => 'none', 'abilities' => ['org.view']])->assertCreated()->json('data.token');

    // members.index opted into ability members.view
    tokAs($mv)->getJson('/api/v1/orgs/'.$org->id.'/members')->assertOk();
    tokAs($noth)->getJson('/api/v1/orgs/'.$org->id.'/members')->assertStatus(403)
        ->assertJsonPath('message', 'This token lacks the required ability: members.view.');

    // org.view ability still cannot cross to members.view
    expect($org->members()->count())->toBeGreaterThan(0);
});

test('AC-006.6 revoke kills the bearer immediately and is audited', function () {
    [$ada, $adaToken] = totpedUser();
    $org = Tenancy::org($ada, 'RevokeCo');
    $created = tokAs($adaToken)->postJson('/api/v1/orgs/'.$org->id.'/tokens', ['name' => 'r', 'abilities' => ['members.view']])->assertCreated()->json('data');

    tokAs($created['token'])->getJson('/api/v1/orgs/'.$org->id.'/members')->assertOk();

    tokAs($adaToken)->deleteJson('/api/v1/orgs/'.$org->id.'/tokens/'.$created['id'])->assertNoContent();
    tokAs($created['token'])->getJson('/api/v1/orgs/'.$org->id.'/members')->assertUnauthorized();

    expect((bool) \DB::table('activity_log')->where('event', 'integration_token_revoked')->exists())->toBeTrue();
});

test('AC-006.6 tokens from another org are invisible (404)', function () {
    [$ada, $adaToken] = totpedUser();
    $orgA = Tenancy::org($ada, 'ATok');
    $orgB = app(CreateOrganizationAction::class)->handle($ada, 'BTok');
    $idA = tokAs($adaToken)->postJson('/api/v1/orgs/'.$orgA->id.'/tokens', ['name' => 'a', 'abilities' => ['members.view']])->json('data.id');

    tokAs($adaToken)->deleteJson('/api/v1/orgs/'.$orgB->id.'/tokens/'.$idA)->assertNotFound();
});

test('AC-006.6 member-role users cannot create tokens (permission 403)', function () {
    [$ada, $adaToken] = totpedUser();
    $org = Tenancy::org($ada, 'NoTok');
    [$bob, $bobToken] = Tenancy::user('bob@notok.test');
    $bob->forceFill(['two_factor_confirmed_at' => now()])->save();
    $org->memberships()->create(['user_id' => $bob->id, 'role' => 'member']);

    tokAs($bobToken)->postJson('/api/v1/orgs/'.$org->id.'/tokens', ['name' => 'x', 'abilities' => ['members.view']])
        ->assertForbidden();
});

test('AC-006.6 integration tokens are exempt from the one-per-device rule and count into the org plan bucket', function () {
    [$ada, $adaToken] = totpedUser();
    [$root] = tokAdmin();
    $org = Tenancy::org($ada, 'BucketCo');

    // five integration tokens coexist (no device_type replacement semantics)
    $tokens = [];
    foreach (range(1, 5) as $i) {
        $tokens[] = tokAs($adaToken)->postJson('/api/v1/orgs/'.$org->id.'/tokens', ['name' => 't'.$i, 'abilities' => ['org.view']])->assertCreated()->json('data.token');
    }
    expect($ada->tokens()->where('kind', 'integration')->count())->toBe(5);

    // shared org budget: drain it with ALTERNATING credentials -> some pass,
    // then every credential 429s (proof the bucket is per-org, not per-token).
    app(SetOrgEntitlementOverrideAction::class)->handle($root, $org, ['api_rate_limit_per_min' => 2]);
    Cache::forget('plan-rl:'.$org->id);

    $codes = [];
    foreach (range(1, 6) as $j) {
        $cred = $j % 2 === 0 ? $adaToken : $tokens[0];
        $codes[] = tokAs($cred)->getJson('/api/v1/orgs/'.$org->id)->status();
    }
    expect(in_array(429, $codes, true))->toBeTrue();
    expect(tokAs($adaToken)->getJson('/api/v1/orgs/'.$org->id)->status())->toBe(429)
        ->and(tokAs($tokens[0])->getJson('/api/v1/orgs/'.$org->id)->status())->toBe(429);
});

/* ───────────────────── schema sanity (T3 migration) ──────────────────────── */

test('personal_access_tokens carries kind + organization_id with index', function () {
    $cols = \Schema::getColumnListing('personal_access_tokens');
    expect($cols)->toContain('organization_id')->toContain('kind');

    $idx = collect(\DB::select('PRAGMA index_list(personal_access_tokens)'))->pluck('name');
    expect($idx->contains(fn (string $n) => str_contains($n, 'organization_id')))->toBeTrue();

    // legacy device rows default to kind=device
    [$ada, $adaToken] = Tenancy::user();
    expect($ada->tokens()->first()->getAttribute('kind'))->toBe('device');
});
