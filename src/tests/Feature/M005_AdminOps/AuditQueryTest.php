<?php

declare(strict_types=1);

namespace Tests\Feature\M005_AdminOps;

use App\Audit\AuditSecurityEvent;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\M003_Billing\BillingHelp;
use Tests\Support\Tenancy;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    $this->seed(PlansSeeder::class);
});

function auditAdmin(): array
{
    $root = User::factory()->create(['email' => 'root@q.test']);
    $root->assignRole('super-admin');

    return [$root, $root->createToken('cli', ['*'])->plainTextToken];
}

function auditAs(string $token)
{
    test()->flushHeaders();
    app('auth')->forgetGuards();

    return test()->withToken($token);
}

test('S6 platform audit query: filters by subject, causer, event, date + cursor', function () {
    [$root, $rt] = auditAdmin();
    [$ada] = Tenancy::user();

    $audit = app(AuditSecurityEvent::class);
    $audit->log('user_suspend', $root, $ada, ['reason' => 'r1 reason here']);
    $audit->log('admin_login', $root, $root);

    // backdate one row for date filtering
    DB::table('activity_log')->where('event', 'user_suspend')->update(['created_at' => '2025-01-01 10:00:00']);

    auditAs($rt)->getJson('/admin/v1/audit?event=user_suspend')->assertOk()->assertJsonCount(1, 'data.audit');
    auditAs($rt)->getJson('/admin/v1/audit?event=admin_login&causer_id='.$root->id)->assertOk()->assertJsonCount(1, 'data.audit');
    auditAs($rt)->getJson('/admin/v1/audit?event=user_suspend&subject_id='.$ada->id)->assertOk()->assertJsonCount(1, 'data.audit');
    auditAs($rt)->getJson('/admin/v1/audit?event=user_suspend&to=2025-06-01')->assertOk()->assertJsonCount(1, 'data.audit');
    auditAs($rt)->getJson('/admin/v1/audit?event=user_suspend&from=2026-01-01')->assertOk()->assertJsonCount(0, 'data.audit');
    auditAs($rt)->getJson('/admin/v1/audit?limit=1')->assertOk()->assertJsonPath('data.next_cursor', fn ($v) => $v !== null);

    // append-only: no mutation routes
    $id = (int) DB::table('activity_log')->max('id');
    auditAs($rt)->patchJson('/admin/v1/audit/'.$id)->assertNotFound();
    auditAs($rt)->deleteJson('/admin/v1/audit/'.$id)->assertNotFound();

    // plain user cannot read the platform trail
    auditAs($ada->createToken('cli', ['*'])->plainTextToken)->getJson('/admin/v1/audit')->assertForbidden();
});

test('Q1 org audit feed: window follows plan entitlement, free pays 402, role matrix holds', function () {
    [, $rt] = auditAdmin();
    [$ada, $adaToken] = Tenancy::user(); // Tenancy::org = pro (90d window)
    $org = Tenancy::org($ada, 'FeedCo');

    // membership create logs (LogsActivity) -> subject exists; add an org update
    $org->forceFill(['require_2fa' => true])->save();

    $feed = auditAs($adaToken)->getJson('/api/v1/orgs/'.$org->id.'/audit')->assertOk();
    expect($feed->json('data.window_days'))->toBe(90)
        ->and($feed->json('data.audit'))->not->toBeEmpty();

    // backdated org update should be outside the 90d window
    DB::table('activity_log')->where('subject_type', Organization::class)->update(['created_at' => now()->subDays(200)->toDateTimeString()]);
    $res = auditAs($adaToken)->getJson('/api/v1/orgs/'.$org->id.'/audit')->assertOk();
    foreach ($res->json('data.audit') as $row) {
        expect($row['subject_type'])->not->toBe(Organization::class);
    }

    // free floor -> 402
    BillingHelp::attachPlan($org, 'free');
    auditAs($adaToken)->getJson('/api/v1/orgs/'.$org->id.'/audit')->assertStatus(402)->assertJsonPath('message', 'Subscription required.');

    // business -> 365 sees the backdated row again
    BillingHelp::attachPlan($org, 'business');
    auditAs($adaToken)->getJson('/api/v1/orgs/'.$org->id.'/audit')->assertOk()
        ->assertJsonPath('data.window_days', 365);
    $seen = collect(auditAs($adaToken)->getJson('/api/v1/orgs/'.$org->id.'/audit')->json('data.audit'))
        ->firstWhere('subject_type', Organization::class);
    expect($seen)->not->toBeNull();

    // member role has no audit.view
    [$bob, $bobToken] = Tenancy::user('bob@q.test');
    Tenancy::addMember($org, $bob);
    auditAs($bobToken)->getJson('/api/v1/orgs/'.$org->id.'/audit')->assertForbidden();

    // override zero kills the feed on business
    auditAs($rt)->putJson('/admin/v1/orgs/'.$org->id.'/entitlements', ['entitlements' => ['audit_retention_days' => 0]])->assertOk();
    auditAs($adaToken)->getJson('/api/v1/orgs/'.$org->id.'/audit')->assertStatus(402);
});

test('Q2 prune: only older than retention goes; dry-run reports; schedule registered', function () {
    $audit = app(AuditSecurityEvent::class);
    [$root] = auditAdmin();

    $audit->log('admin_login', $root, $root);
    DB::table('activity_log')->insert([
        'log_name' => 'audit', 'description' => 'ancient', 'event' => 'ancient',
        'created_at' => now()->subDays(400)->toDateTimeString(), 'updated_at' => now()->subDays(400)->toDateTimeString(),
    ]);

    $this->artisan('audit:prune', ['--dry-run' => true])->assertSuccessful()->assertExitCode(0);
    expect(DB::table('activity_log')->whereIn('event', ['admin_login', 'ancient'])->count())->toBe(2);

    $this->artisan('audit:prune')->assertSuccessful();
    expect(DB::table('activity_log')->whereIn('event', ['admin_login', 'ancient'])->count())->toBe(1)
        ->and(DB::table('activity_log')->whereIn('event', ['admin_login', 'ancient'])->value('event'))->toBe('admin_login');

    Artisan::call('schedule:list', ['--json' => true]);
    $events = collect(json_decode(Artisan::output(), true));

    expect($events->first(fn ($e) => str_contains((string) ($e['command'] ?? ''), 'audit:prune'))['expression'])->toBe('0 3 * * *');
});
