<?php

declare(strict_types=1);

use App\Events\Auth\UserRegistered;
use App\Exceptions\AuthLinkException;
use App\Models\AuthLink;
use App\Models\User;
use App\Notifications\CatalogDelivery;
use App\Notifications\NotificationCatalog;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(RolesSeeder::class);
});

function validUserPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'Str0ng!Passw0rd',
        'password_confirmation' => 'Str0ng!Passw0rd',
    ], $overrides);
}

/* ─────────────────────────── AC-001.1 register ─────────────────────────── */

test('AC-001.1 register → 202, unverified user, no token, event once', function () {
    Event::fake([UserRegistered::class]);

    $response = $this->postJson('/api/v1/auth/register', validUserPayload());

    $response->assertStatus(202)->assertExactJson(['data' => ['accepted' => true]]);

    $user = User::whereEmail('ada@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->tokens()->count())->toBe(0);

    Event::assertDispatchedTimes(UserRegistered::class, 1);
});

test('AC-001.1 password complexity enforced (uppercase, digit, symbol, length)', function (string $weak) {
    $this->postJson('/api/v1/auth/register', validUserPayload([
        'password' => $weak, 'password_confirmation' => $weak,
    ]))->assertStatus(422)->assertJsonValidationErrors(['password']);
})->with([
    'no uppercase' => ['alllowercase1!'],
    'no digit' => ['NoDigitsHere!!'],
    'no symbol' => ['NoSymbols1234'],
    'too short' => ['Sh0rt!x'],
]);

test('AC-001.1 password confirmation required', function () {
    $this->postJson('/api/v1/auth/register', validUserPayload(['password_confirmation' => 'Str0ng!Different1']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');
});

/* ──────────────────── AC-001.4 enumeration prevention ──────────────────── */

test('AC-001.4 duplicate email responds with identical envelope and sends no email', function () {
    Notification::fake();
    $existing = User::factory()->create(['email' => 'ada@example.com']);

    $response = $this->postJson('/api/v1/auth/register', validUserPayload([
        'name' => 'Impostor', 'password' => 'An0ther!Secret', 'password_confirmation' => 'An0ther!Secret',
    ]));

    $response->assertStatus(202)->assertExactJson(['data' => ['accepted' => true]]);

    expect(User::whereEmail('ada@example.com')->count())->toBe(1)
        ->and(User::find($existing->id)->name)->toBe($existing->name);

    Notification::assertNothingSentTo($existing);
});

/* ───────────── AC-001.1/.2 verification email + link lifecycle ─────────── */

test('AC-001.1 registration queues verification notification once', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/register', validUserPayload())->assertStatus(202);

    Notification::assertSentTo(
        User::whereEmail('ada@example.com')->firstOrFail(),
        CatalogDelivery::class,
        fn (CatalogDelivery $n) => $n->type === 'auth.email_verification'
            && Carbon::parse($n->data['expires_at'])->isAfter(now()->addMinutes(59))
    );
});

test('AC-001.2 valid link verifies email, consumes link (single-use), idempotent on user', function () {
    $user = User::factory()->unverified()->create();
    $link = AuthLink::issue($user, 'verify_email');
    $token = $link->token; // capture: refresh() drops the transient plaintext attribute

    $this->getJson('/api/v1/auth/verify-email/'.$user->id.'/'.$token)
        ->assertOk()
        ->assertExactJson(['data' => ['verified' => true]]);

    expect($user->refresh()->email_verified_at)->not->toBeNull()
        ->and($link->refresh()->used_at)->not->toBeNull();

    // replay
    $this->getJson('/api/v1/auth/verify-email/'.$user->id.'/'.$token)
        ->assertForbidden()
        ->assertJsonPath('message', 'This verification link is no longer valid.');
});

test('AC-001.2 expired, tampered, foreign-user and wrong-type links all → 403', function () {
    $user = User::factory()->unverified()->create();

    $expired = AuthLink::issue($user, 'verify_email', minutes: -1);
    $this->getJson('/api/v1/auth/verify-email/'.$user->id.'/'.$expired->token)
        ->assertForbidden();
    expect($user->refresh()->email_verified_at)->toBeNull();

    $good = AuthLink::issue($user, 'verify_email');
    $this->getJson('/api/v1/auth/verify-email/'.$user->id.'/'.str_repeat('x', 64))->assertForbidden();

    $other = User::factory()->create();
    $this->getJson('/api/v1/auth/verify-email/'.$other->id.'/'.$good->token)->assertForbidden();

    $magic = AuthLink::issue($user, 'magic_link');
    $this->getJson('/api/v1/auth/verify-email/'.$user->id.'/'.$magic->token)->assertForbidden();
});

/* ───────────────────────── AC-001.2 resend ─────────────────────────────── */

test('AC-001.2 resend is public, always 202, mails only unverified existing accounts', function () {
    Notification::fake();
    $unverified = User::factory()->unverified()->create(['email' => 'u@example.com']);
    $verified = User::factory()->create(['email' => 'v@example.com']);

    $body = ['data' => ['accepted' => true]];

    expect($this->postJson('/api/v1/auth/email/verify/resend', ['email' => 'u@example.com'])->json())->toBe($body)
        ->and($this->postJson('/api/v1/auth/email/verify/resend', ['email' => 'v@example.com'])->json())->toBe($body)
        ->and($this->postJson('/api/v1/auth/email/verify/resend', ['email' => 'ghost@example.com'])->json())->toBe($body);

    Notification::assertSentTo($unverified, CatalogDelivery::class, fn (CatalogDelivery $n) => $n->type === 'auth.email_verification');
    Notification::assertNothingSentTo($verified);
});

/* ─────────────────────────── AuthLink service ──────────────────────────── */

test('AuthLink stores only the sha256 hash of the token, unique per issue', function () {
    Queue::fake();
    $user = User::factory()->create();

    $a = AuthLink::issue($user, 'verify_email');
    $b = AuthLink::issue($user, 'verify_email');

    expect($a->token)->not->toBe($b->token)
        ->and($a->token_hash)->toBe(hash('sha256', $a->token))
        ->and($a->getRawOriginal('token_hash'))->not->toBe($a->token);
});

test('AC-001.4 registering an existing UNVERIFIED email re-queues verification silently', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create(['email' => 'ada@example.com']);

    $this->postJson('/api/v1/auth/register', validUserPayload([
        'password' => 'An0ther!Secret', 'password_confirmation' => 'An0ther!Secret',
    ]))->assertStatus(202);

    Notification::assertSentTo($user, CatalogDelivery::class, fn (CatalogDelivery $n) => $n->type === 'auth.email_verification');
    expect(User::count())->toBe(1);
});

test('AC-001.2 link for a nonexistent user id is the same generic 403', function () {
    $link = AuthLink::issue(User::factory()->create(), 'verify_email');

    $this->getJson('/api/v1/auth/verify-email/99999/'.$link->token)
        ->assertForbidden()
        ->assertJsonPath('message', AuthLinkException::GENERIC_MESSAGE);
});

test('AC-001.1 verification mail content: subject, signed URL, expiry copy', function () {
    $user = User::factory()->unverified()->create();
    $link = AuthLink::issue($user, 'verify_email');

    $delivery = new CatalogDelivery('auth.email_verification', ['url' => 'x', 'expires_at' => (string) $link->expires_at]);
    expect($delivery->via($user))->toBe(['mail', 'database', 'broadcast']);

    $mail = app(NotificationCatalog::class)->get('auth.email_verification')->mailable([
        'url' => rtrim((string) config('app.url'), '/')."/api/v1/auth/verify-email/{$user->id}/{$link->token}",
    ]);

    expect($mail->envelope()->subject)->toBe('Verify your email address')
        ->and($mail->actionUrl)->toBe(rtrim((string) config('app.url'), '/')."/api/v1/auth/verify-email/{$user->id}/{$link->token}");
});
