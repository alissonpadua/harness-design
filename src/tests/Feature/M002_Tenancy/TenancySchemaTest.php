<?php

declare(strict_types=1);

use App\Enums\MemberStatus;
use App\Enums\OrgRole;
use App\Enums\OrgType;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\OrganizationInviteLink;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Tenancy;

test('organizations tables + columns exist', function () {
    expect(Schema::hasTable('organizations'))->toBeTrue()
        ->and(Schema::hasTable('organization_user'))->toBeTrue()
        ->and(Schema::hasTable('organization_invites'))->toBeTrue()
        ->and(Schema::hasTable('organization_invite_links'))->toBeTrue()
        ->and(array_values(array_diff([
            'name', 'slug', 'type', 'owner_id', 'logo_url', 'domain',
            'default_member_role', 'require_2fa', 'invite_only', 'deleted_at',
        ], Schema::getColumnListing('organizations'))))->toBe([])
        ->and(array_values(array_diff(
            ['organization_id', 'user_id', 'role', 'status', 'invited_by'],
            Schema::getColumnListing('organization_user')
        )))->toBe([]);
});

test('organization_user unique per org+user; invites unique token hash', function () {
    $org = Organization::create(['name' => 'A', 'owner_id' => User::factory()->create()->id, 'type' => OrgType::Team]);
    $user = User::factory()->create();
    OrganizationMembership::query()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => 'member', 'status' => 'active']);

    expect(fn () => OrganizationMembership::query()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => 'admin', 'status' => 'active']))
        ->toThrow(UniqueConstraintViolationException::class);

    $invite = OrganizationInvite::makeWithToken();
    $invite->fill(['organization_id' => $org->id, 'email' => 'x@y.z', 'role' => 'member', 'invited_by' => $user->id, 'expires_at' => now()->addDay()])->save();
    expect($invite->refresh()->token_hash)->toBe(hash('sha256', $invite->token()))
        ->and($invite->token())->not->toBe($invite->token_hash);
});

test('slug auto-generation with collision suffix', function () {
    $owner = User::factory()->create();
    $a = Organization::create(['name' => 'Cool Corp', 'owner_id' => $owner->id, 'type' => OrgType::Team]);
    $b = Organization::create(['name' => 'Cool Corp', 'owner_id' => $owner->id, 'type' => OrgType::Team]);
    $c = Organization::create(['name' => 'Cool Corp', 'owner_id' => $owner->id, 'type' => OrgType::Team]);

    expect([$a->slug, $b->slug, $c->slug])->toBe(['cool-corp', 'cool-corp-2', 'cool-corp-3']);
});

test('cast round-trips enums', function () {
    $org = Tenancy::org(User::factory()->create());
    $member = OrganizationMembership::query()->first();

    expect($org->type)->toBe(OrgType::Team)
        ->and($member->role)->toBe(OrgRole::Owner)
        ->and($member->status)->toBe(MemberStatus::Active);
});

test('OrganizationScope is fail-closed for bare queries and respects explicit parent constraints', function () {
    [$ada] = Tenancy::user();
    $acme = Tenancy::org($ada);
    $other = Tenancy::org($ada, 'Other');
    Tenancy::switchTo($ada, $acme);
    $inviteInOther = OrganizationInvite::makeWithToken();
    $inviteInOther->fill(['organization_id' => $other->id, 'email' => 'a@b.c', 'role' => 'member', 'invited_by' => $ada->id, 'expires_at' => now()->addDay()])->save();

    // acting as ada with current=acme
    $this->actingAs($ada);
    expect(OrganizationInvite::query()->pluck('id')->all())->toBe([])          // bare query: current org only
        ->and($other->invites()->pluck('id')->all())->toBe([$inviteInOther->id]); // explicit parent: untouched

    // HTTP without authenticated user → fail-closed (console/seeder context is exempt by design)
    Route::get('api/v1/__t_bare_invites', fn () => ['data' => ['count' => OrganizationInvite::query()->count()]]);
    expect($this->getJson('/api/v1/__t_bare_invites')->json('data.count'))->toBe(0);

    // token lookup ignores scope (capability)
    expect(OrganizationInvite::findByRawToken($inviteInOther->token())?->id)->toBe($inviteInOther->id);
});

test('invite link max uses affects usability', function () {
    [$ada] = Tenancy::user();
    $org = Tenancy::org($ada);
    $link = OrganizationInviteLink::makeWithToken();
    $link->fill(['organization_id' => $org->id, 'role' => 'member', 'created_by' => $ada->id, 'max_uses' => 1, 'uses' => 1])->save();

    expect($link->isUsable())->toBeFalse();
    $link->uses = 0;
    expect($link->isUsable())->toBeTrue();
});
