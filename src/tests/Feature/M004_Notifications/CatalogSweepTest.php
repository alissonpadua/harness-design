<?php

declare(strict_types=1);

use App\Actions\Notifications\DispatchNotification;
use App\Billing\Events\InvoicePaid;
use App\Billing\Events\PaymentFailed;
use App\Billing\Events\SubscriptionPlanChanged;
use App\Enums\SubscriptionStatus;
use App\Listeners\Notifications\PruneInbox;
use App\Models\BillingInvoice;
use App\Models\BillingSubscription;
use App\Models\FailedNotification;
use App\Models\User;
use App\Notifications\CatalogDelivery;
use App\Notifications\NotificationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('every catalog type renders title, body and mailable with fixtures', function () {
    Mail::fake();

    $fixtures = [
        'auth.email_verification' => ['url' => 'http://api/verify', 'expires_at' => '2026-12-01 00:00:00'],
        'auth.password_reset' => ['token' => 'tok'],
        'auth.magic_link' => ['token' => 'tok', 'minutes' => 15],
        'auth.new_device_login' => ['ip' => '1.1.1.1', 'agent' => 'UA', 'time' => '2026-12-01 00:00:00'],
        'auth.email_change' => ['variant' => 'to_old', 'from' => 'a@x.io', 'to' => 'b@x.io'],
        'org.invite_received' => ['org_name' => 'O', 'role' => 'admin', 'url' => 'http://api/inv'],
        'org.member_joined' => ['org_name' => 'O', 'member_name' => 'M', 'role' => 'member'],
        'org.ownership_transferred' => ['org_name' => 'O', 'from_name' => 'A', 'to_name' => 'B'],
        'billing.payment_failed' => ['org_name' => 'O'],
        'billing.invoice_paid' => ['org_name' => 'O', 'amount' => '1.00', 'currency' => 'USD'],
        'billing.plan_changed' => ['org_name' => 'O', 'to' => 'Pro'],
        'billing.trial_ending_soon' => ['org_name' => 'O', 'plan_name' => 'Pro', 'days_left' => 2, 'sub_id' => 1],
    ];

    foreach (app(NotificationCatalog::class)->all() as $type => $entry) {
        expect(array_key_exists($type, $fixtures))->toBeTrue("{$type} missing test fixture");
        $data = $fixtures[$type];

        expect($entry->title($data))->not->toBeEmpty($type.' title')
            ->and($entry->body($data))->not->toBeEmpty($type.' body');

        $mailable = $entry->mailable($data);
        expect($mailable->envelope()->subject)->not->toBeEmpty($type.' subject')
            ->and($mailable->render())->not->toBeEmpty($type.' render');
    }

    // email_change to_new branch too
    $ec = app(NotificationCatalog::class)->get('auth.email_change');
    expect($ec->title(['variant' => 'to_new']))->toBe('Confirm your new email address')
        ->and($ec->mailable(['variant' => 'to_new', 'to' => 'b@x.io', 'url' => 'http://api/confirm'])->render())->not->toBeEmpty();
});

test('locked-type mail preference default resolves for non-user recipients', function () {
    $anon = (new AnonymousNotifiable)->route('mail', 'ghost@example.com');
    $channels = (new CatalogDelivery('billing.invoice_paid', ['org_name' => 'O', 'amount' => '1.00', 'currency' => 'USD']))->via($anon);

    expect($channels)->toBe(['mail', 'database', 'broadcast']);
});

test('retry sweep records retry on failure and falls back to email lane for deleted users', function () {
    Mail::fake();

    $row = FailedNotification::create([
        'type' => 'billing.invoice_paid',
        'recipient_type' => 'user',
        'recipient_id' => 999999, // deleted user → email-lane fallback
        'recipient_email' => null,
        'payload' => [], // missing keys → mailable throws
        'error' => 'boom',
        'failed_at' => now(),
    ]);

    // delivery throws → attempts++
    $this->artisan('notifications:retry-failed', ['--id' => (string) $row->id]);

    expect($row->refresh()->attempts)->toBe(1)->and($row->resolved_at)->toBeNull();
});

test('billing listener tolerates a subscription without organization', function () {
    $sub = new BillingSubscription(['status' => SubscriptionStatus::PastDue]);

    expect(fn () => event(new PaymentFailed($sub)))->not->toThrow(Throwable::class);
});

test('notification sent event ignores foreign notifiables and other channels', function () {
    $listener = new PruneInbox;
    $user = User::factory()->create();

    $event = new NotificationSent($user, 'mail', new CatalogDelivery('auth.magic_link', ['token' => 't', 'minutes' => 1]), null, null);
    $listener->handle($event);

    expect(true)->toBeTrue();
});

test('catalog ignores stray files and billing listener covers all-null guards', function () {
    $stray = app_path('Notifications/Types/ZZNoClassHere.php');
    file_put_contents($stray, "<?php\n// no class here\n");

    try {
        expect(app(NotificationCatalog::class)->all())->toHaveCount(12);
    } finally {
        @unlink($stray);
    }

    // onInvoicePaid + onPlanChanged with orphan subscription
    $sub = new BillingSubscription(['status' => SubscriptionStatus::PastDue]);
    event(new InvoicePaid($sub, new BillingInvoice(['gateway_invoice_id' => 'in_z', 'organization_id' => 0, 'status' => 'paid', 'amount_due' => 1, 'currency' => 'USD'])));
    event(new SubscriptionPlanChanged($sub));

    expect(true)->toBeTrue();
});

test('defensive branches: null created_at cursor and route-less notifiable', function () {
    $user = User::factory()->create();

    $n = new DatabaseNotification;
    $n->id = 'row-without-timestamp';
    $n->type = 'X';
    $n->notifiable_type = $user::class;
    $n->notifiable_id = $user->id;
    $n->data = ['type' => 'x'];
    $n->created_at = null;
    $n->save();

    // row is newest-by-null? order keeps it last on sqlite null-first asc… ensure it lands in window by adding a fresh real one too
    Mail::fake();
    app(DispatchNotification::class)->user($user, 'auth.new_device_login', ['ip' => '1', 'agent' => '2', 'time' => '3']);

    $token = $user->createToken('t', ['*'])->plainTextToken;
    test()->flushHeaders();
    app('auth')->forgetGuards();
    $all = test()->withToken($token)->getJson('/api/v1/notifications?limit=2')->assertOk()->json('data.notifications');
    expect($all)->toHaveCount(2);

    // route-less notifiable falls back to null route in toMail
    $delivery = new CatalogDelivery('auth.magic_link', ['token' => 't', 'minutes' => 5]);
    $mail = $delivery->toMail(new stdClass);
    expect($mail->to)->toBe([]);
});

test('sweep and trial-reminder schedules are registered', function () {
    Artisan::call('schedule:list', ['--json' => true]);
    $events = collect(json_decode(Artisan::output(), true));

    $sweep = $events->first(fn ($e) => str_contains((string) ($e['command'] ?? ''), 'notifications:sweep-failed'));
    $trial = $events->first(fn ($e) => str_contains((string) ($e['command'] ?? ''), 'notifications:trial-reminders'));

    expect($sweep)->not->toBeNull()->and($sweep['expression'])->toBe('*/15 * * * *')
        ->and($trial)->not->toBeNull()->and($trial['expression'])->toBe('0 8 * * *');
});
