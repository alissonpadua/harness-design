<?php

declare(strict_types=1);

namespace Tests\Feature\M005_AdminOps;

use App\Audit\AuditSecurityEvent;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Tenancy;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesSeeder::class);
});

test('T1 schema: admin-ops columns and tables exist', function () {
    expect(Schema::hasColumn('personal_access_tokens', 'impersonator_id'))
        ->toBeTrue()
        ->and(Schema::hasColumn('users', 'suspended_at'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'suspend_reason'))->toBeTrue()
        ->and(Schema::hasColumn('subscriptions', 'admin_locked'))->toBeTrue()
        ->and(Schema::hasTable('organization_entitlement_overrides'))->toBeTrue()
        ->and(Schema::hasTable('activity_log'))->toBeTrue();
});

test('T1 model audits: create + dirty whitelisted updates land; off-list attrs never do', function () {
    [$ada] = Tenancy::user();
    $org = Tenancy::org($ada, 'Audited');

    $org->forceFill(['name' => 'Renamed', 'require_2fa' => true])->save();
    $org->forceFill(['logo_hash' => str_repeat('a', 64)])->save(); // off-whitelist -> no row

    $rows = DB::table('activity_log')
        ->where('subject_type', Organization::class)
        ->orderBy('id')
        ->get();

    expect($rows->pluck('event')->all())->toBe(['created', 'updated'])
        ->and($rows[1]->description)->toBe('updated');

    $changes = json_decode((string) $rows[1]->attribute_changes, true);
    expect($changes['attributes']['name'])->toBe('Renamed')
        ->and($changes['old']['name'])->toBe('Audited')
        ->and($changes['attributes'])->not->toHaveKey('logo_hash');

    expect(DB::table('activity_log')->where('subject_type', User::class)->count())->toBe(0);
});

test('T1 user rows audit via explicit security events, not model traits (v5 LogsActivity/sanctum leak)', function () {
    [$ada] = Tenancy::user();
    $admin = User::factory()->create(['email' => 'root@b.test']);
    $admin->assignRole('super-admin');

    app(AuditSecurityEvent::class)->log('user_suspend', $admin, $ada, ['reason' => 'abuse']);

    expect(DB::table('activity_log')->where('subject_type', User::class)->count())->toBe(1)
        ->and(DB::table('activity_log')->where('event', 'user_suspend')->value('log_name'))->toBe('audit');
});

test('T1 security events: registered events write causer+subject rows; unknown rejected', function () {
    [$ada] = Tenancy::user();
    $admin = User::factory()->create(['email' => 'root@a.test']);
    $admin->assignRole('super-admin');

    app(AuditSecurityEvent::class)->log('user_suspend', $admin, $ada, ['reason' => 'abuse']);

    $row = DB::table('activity_log')->where('event', 'user_suspend')->first();
    expect($row)->not->toBeNull()
        ->and($row->causer_id)->toBe($admin->id)
        ->and($row->log_name)->toBe('audit')
        ->and(json_decode((string) $row->properties, true)['reason'])->toBe('abuse');

    expect(fn () => app(AuditSecurityEvent::class)->log('sneaky_event'))->toThrow(\InvalidArgumentException::class);
});
