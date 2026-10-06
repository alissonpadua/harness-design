<?php

declare(strict_types=1);

use App\Actions\Notifications\DispatchNotification;
use App\Actions\Org\CreateOrganizationAction;
use App\Billing\Events\PaymentFailed;
use App\Billing\Events\SubscriptionPlanChanged;
use App\Enums\MemberStatus;
use App\Enums\OrgRole;
use App\Enums\SubscriptionStatus;
use App\Events\Org\MemberJoined;
use App\Jobs\Notifications\DeliverNotification;
use App\Models\FailedNotification;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\CatalogDelivery;
use App\Notifications\NotificationCatalog;
use Database\Seeders\PlansSeeder;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'inbox@example.com']);
});

function m4as(string $token)
{
    test()->flushHeaders();
    app('auth')->forgetGuards();

    return test()->withToken($token);
}

/* ── AC-004.1 catalog ── */

test('catalog exposes exactly the locked 12 types', function () {
    $types = array_keys(app(NotificationCatalog::class)->all());

    expect($types)->toBe([
        'auth.email_change',
        'auth.email_verification',
        'auth.magic_link',
        'auth.new_device_login',
        'auth.password_reset',
        'billing.invoice_paid',
        'billing.payment_failed',
        'billing.plan_changed',
        'billing.trial_ending_soon',
        'org.invite_received',
        'org.member_joined',
        'org.ownership_transferred',
    ]);

    expect(fn () => app(NotificationCatalog::class)->get('nope.nope'))->toThrow(InvalidArgumentException::class);
});

/* ── AC-004.5/.7 persistence + list + prune ── */

test('delivery persists inbox row and serves the list endpoint', function () {
    Mail::fake();
    app(DispatchNotification::class)->user($this->user, 'auth.new_device_login', [
        'ip' => '1.2.3.4', 'agent' => 'Chrome', 'time' => now()->toIso8601String(),
    ]);

    $row = DatabaseNotification::latest('id')->firstOrFail();
    expect($row->data['type'])->toBe('auth.new_device_login')
        ->and($row->data['title'])->toBe('New sign-in to your account');

    $token = $this->user->createToken('t', ['*'])->plainTextToken;
    m4as($token)->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.notifications.0.type', 'auth.new_device_login')
        ->assertJsonStructure(['data' => ['notifications' => [['id', 'type', 'title', 'body', 'data', 'created_at']], 'next_cursor']]);

    // other user sees nothing
    $other = User::factory()->create();
    m4as($other->createToken('t', ['*'])->plainTextToken)
        ->getJson('/api/v1/notifications')
        ->assertOk()->assertJsonCount(0, 'data.notifications');
});

test('inbox prunes to newest 100 rows per user', function () {
    Mail::fake();
    $userClass = $this->user::class;

    foreach (range(1, 105) as $i) {
        DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => CatalogDelivery::class,
            'notifiable_type' => $userClass,
            'notifiable_id' => $this->user->id,
            'data' => ['type' => 'auth.new_device_login', 'title' => "seed {$i}", 'body' => '', 'data' => []],
            'created_at' => now()->subMinutes(200 - $i),
        ]);
    }

    app(DispatchNotification::class)->user($this->user, 'auth.new_device_login', ['ip' => 'x', 'agent' => 'a', 'time' => now()->toIso8601String()]);

    expect(DatabaseNotification::query()->where('notifiable_id', $this->user->id)->count())->toBe(100)
        ->and(DatabaseNotification::query()->where('notifiable_id', $this->user->id)->orderBy('created_at')->value('data'))->not->toBeNull();
});

test('cursor pagination walks the inbox newest-first', function () {
    Mail::fake();
    $disp = app(DispatchNotification::class);

    foreach (range(1, 3) as $i) {
        $disp->user($this->user, 'auth.new_device_login', ['ip' => "1.1.1.{$i}", 'agent' => 'a', 'time' => now()->subMinutes(10 - $i)->toIso8601String()]);
    }

    $token = $this->user->createToken('t', ['*'])->plainTextToken;
    $page1 = m4as($token)->getJson('/api/v1/notifications?limit=2')->assertOk()->json('data');
    expect($page1['notifications'])->toHaveCount(2)->and($page1['next_cursor'])->not->toBeNull();

    $page2 = m4as($token)->getJson('/api/v1/notifications?limit=2&cursor='.urlencode((string) $page1['next_cursor']))->assertOk()->json('data');
    expect($page2['notifications'])->toHaveCount(1)->and($page2['next_cursor'])->toBeNull();
});

/* ── AC-004.5/.6 broadcast + channel auth ── */

test('broadcast payload mirrors the inbox shape and targets user channel', function () {
    $delivery = new CatalogDelivery('org.member_joined', ['org_name' => 'Acme', 'member_name' => 'Bob', 'role' => 'member']);

    expect($delivery->broadcastType())->toBe('notification.org.member_joined')
        ->and($delivery->toBroadcast($this->user)->data['title'])->toBe('New member in Acme')
        ->and($this->user->routeNotificationForBroadcast($delivery))->toBe('user.'.$this->user->id);
});

test('channel callback from routes/channels.php admits only the owner', function () {
    $recorder = new class extends Broadcaster
    {
        /** @return array<string, mixed> */
        public function registered(): array
        {
            return $this->channels;
        }

        public function auth($request) {}

        public function validAuthenticationResponse($request, $result) {}

        public function broadcast(array $channels, $event, array $payload = []) {}
    };

    Broadcast::swap($recorder);
    require base_path('routes/channels.php');

    $callback = $recorder->registered()['user.{id}'];
    $other = User::factory()->create();

    expect($callback($this->user, (string) $this->user->id))->toBeTrue()
        ->and($callback($this->user, (string) $other->id))->toBeFalse()
        ->and($callback($other, (string) $this->user->id))->toBeFalse();

    expect(collect(app('router')->getRoutes()->getRoutes())->contains(fn ($r) => $r->uri() === 'broadcasting/auth'))->toBeTrue();
});

/* ── AC-004.8/.9 preferences ── */

test('preferences matrix, toggle, suppression and locked rejection', function () {
    $token = $this->user->createToken('t', ['*'])->plainTextToken;

    $matrix = m4as($token)->getJson('/api/v1/notifications/preferences')->assertOk()->json('data.preferences');
    expect($matrix)->toHaveCount(12);
    $locked = collect($matrix)->firstWhere('type', 'auth.password_reset');
    expect($locked['locked'])->toBeTrue()->and($locked['email_enabled'])->toBeTrue();

    // disable a non-locked type
    m4as($token)->putJson('/api/v1/notifications/preferences', ['notifications' => ['billing.invoice_paid' => ['email' => false]]])
        ->assertOk();
    expect(NotificationPreference::query()->where('type', 'billing.invoice_paid')->value('email_enabled'))->toBeFalse();

    // email suppressed but inbox row still lands
    Mail::fake();
    app(DispatchNotification::class)->user($this->user->fresh(), 'billing.invoice_paid', ['org_name' => 'A', 'amount' => '1.00', 'currency' => 'USD']);
    Mail::assertNothingSent();
    expect(DatabaseNotification::query()->where('data->type', 'billing.invoice_paid')->exists())->toBeTrue();

    // locked + unknown rejections
    $locked = m4as($token)->putJson('/api/v1/notifications/preferences', ['notifications' => ['auth.magic_link' => ['email' => false]]])
        ->assertStatus(422)->assertJsonValidationErrors('notifications.auth.magic_link');
    expect($locked->json('errors')['notifications.auth.magic_link'][0])->toBe('This notification type cannot be changed.');

    $unknown = m4as($token)->putJson('/api/v1/notifications/preferences', ['notifications' => ['ghost.type' => ['email' => false]]])
        ->assertStatus(422)->assertJsonValidationErrors('notifications.ghost.type');
    expect($unknown->json('errors')['notifications.ghost.type'][0])->toBe('Unknown notification type.');
});

/* ── AC-004.14/.15 failure capture + retry ── */

test('failed sends are recorded, retried with backoff and parked; manual command overrides', function () {
    $row = FailedNotification::create([
        'type' => 'auth.new_device_login',
        'recipient_type' => 'user',
        'recipient_id' => $this->user->id,
        'payload' => ['ip' => '9.9.9.9', 'agent' => 'x', 'time' => now()->toIso8601String()],
        'error' => 'smtp exploded',
        'failed_at' => now(),
    ]);

    // backoff math
    $row->markAttempt('again');
    expect($row->attempts)->toBe(1)->and($row->next_retry_at->isAfter(now()->addMinutes(20)))->toBeTrue();
    $row->markAttempt('again');
    $row->markAttempt('final');
    expect($row->attempts)->toBe(3)->and($row->next_retry_at)->toBeNull()->and($row->isRedeliverable())->toBeFalse();

    // sweep skips parked rows
    $this->artisan('notifications:sweep-failed')->assertSuccessful();
    expect($row->refresh()->resolved_at)->toBeNull();

    // manual --all redelivers
    Mail::fake();
    $this->artisan('notifications:retry-failed', ['--all' => true])->assertSuccessful();
    expect($row->refresh()->resolved_at)->not->toBeNull();
});

test('job records failure evidence when delivery explodes', function () {
    $this->expectException(Throwable::class);
    app(DispatchNotification::class)->email('', 'auth.new_device_login', ['ip' => 'x', 'agent' => 'y', 'time' => 'z']);
})->after(function () {
    expect(FailedNotification::query()->where('type', 'auth.new_device_login')->where('error', 'like', '%To%')->exists())->toBeTrue();
});

/* ── AC-004.13 trial reminders ── */

test('trial reminders notify owner+admins inside the window only', function () {
    $this->seed(PlansSeeder::class);
    [$org] = m4org();

    $sub = $org->subscription();
    $sub->forceFill(['status' => 'trialing', 'trial_end' => now()->addDays(2)])->save();

    Notification::fake();
    $this->artisan('notifications:trial-reminders')->assertSuccessful();
    Notification::assertSentTo($org->owner, CatalogDelivery::class, fn (CatalogDelivery $n) => $n->type === 'billing.trial_ending_soon' && $n->data['days_left'] >= 1);

    // outside window → nothing
    Notification::fake();
    $sub->forceFill(['trial_end' => now()->addDays(9)])->save();
    $this->artisan('notifications:trial-reminders')->assertSuccessful();
    Notification::assertNothingSent();
});

test('trial reminder fires once per subscription (dedupe on mirrored inbox row)', function () {
    $this->seed(PlansSeeder::class);
    [$org] = m4org();

    $sub = $org->subscription();
    $sub->forceFill(['status' => 'trialing', 'trial_end' => now()->addDays(2)])->save();

    // real delivery #1: rows must land in the inbox for dedupe to see them
    Mail::fake();
    app(DispatchNotification::class)->org($org, 'billing.trial_ending_soon', [
        'org_name' => $org->name, 'plan_name' => $sub->plan->name, 'days_left' => 2, 'sub_id' => $sub->id,
    ]);
    expect(DatabaseNotification::query()->where('data->type', 'billing.trial_ending_soon')->count())->toBeGreaterThan(0);

    Notification::fake();
    $this->artisan('notifications:trial-reminders')->assertSuccessful();
    Notification::assertNothingSent();
});

function m4org(): array
{
    $owner = User::factory()->create();
    $org = app(CreateOrganizationAction::class)->handle($owner, 'TrialCo');
    $admin = User::factory()->create(['email' => 'admin@m4.test']);
    $org->memberships()->create(['organization_id' => $org->id, 'user_id' => $admin->id, 'role' => OrgRole::Admin, 'status' => MemberStatus::Active]);

    return [$org, $admin];
}

/* ── AC-004.2 generator ── */

test('notification:make scaffolds a discovered type; bad input rejected', function () {
    $path = app_path('Notifications/Types/SampleProbe.php');
    @unlink($path);

    $this->artisan('notification:make', ['name' => 'SampleProbe', '--type' => 'custom.sample_probe'])->assertSuccessful();
    expect(File::exists($path))->toBeTrue();

    try {
        $catalog = app(NotificationCatalog::class);
        expect($catalog->has('custom.sample_probe'))->toBeTrue();

        $this->artisan('notification:make', ['name' => 'SampleProbe'])->assertFailed(); // duplicate
        $this->artisan('notification:make', ['name' => 'BadSlug', '--type' => 'bad slug'])->assertFailed();
        @unlink(app_path('Notifications/Types/BadSlug.php'));

        expect($catalog->get('custom.sample_probe')->locked())->toBeFalse()
            ->and($catalog->all())->toHaveKey('custom.sample_probe');
    } finally {
        @unlink($path);
    }
});

/* ── AC-004.11/.12 wiring: events reach inboxes ── */

test('org + billing domain events fan out catalog notifications', function () {
    Notification::fake();

    [$org, $admin] = m4org();
    $bob = User::factory()->create(['name' => 'Bob']);
    event(new MemberJoined($org, $bob));
    Notification::assertSentTo($org->owner, CatalogDelivery::class, fn (CatalogDelivery $n) => $n->type === 'org.member_joined' && $n->data['member_name'] === 'Bob');

    $sub = $org->subscription();
    $sub->forceFill(['status' => SubscriptionStatus::PastDue, 'past_due_since' => now()])->save();
    event(new PaymentFailed($sub));
    Notification::assertSentTo($admin, CatalogDelivery::class, fn (CatalogDelivery $n) => $n->type === 'billing.payment_failed');

    $sub->forceFill(['status' => SubscriptionStatus::Active, 'past_due_since' => null])->save();
    event(new SubscriptionPlanChanged($sub));
    Notification::assertSentTo($org->owner, CatalogDelivery::class, fn (CatalogDelivery $n) => $n->type === 'billing.plan_changed');
});

test('queued mail: catalog mailable is queued not sync-inline', function () {
    Notification::fake();
    app(DispatchNotification::class)->user($this->user, 'auth.new_device_login', ['ip' => 'x', 'agent' => 'a', 'time' => now()->toIso8601String()]);
    Notification::assertSentTo($this->user, CatalogDelivery::class);
    // deliver job itself is queued:
    Queue::fake();
    app(DispatchNotification::class)->user($this->user, 'auth.new_device_login', ['ip' => 'x', 'agent' => 'a', 'time' => now()->toIso8601String()]);
    Queue::assertPushed(DeliverNotification::class);
    unset($queue);
});
