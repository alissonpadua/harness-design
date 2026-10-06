<?php

declare(strict_types=1);

use App\Events\Auth\EmailChanged;
use App\Events\Auth\PasswordChanged;
use App\Exceptions\AuthLinkException;
use App\Models\AuthLink;
use App\Models\User;
use App\Notifications\CatalogDelivery;
use App\Notifications\NotificationCatalog;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

beforeEach(function () {
    $this->seed(RolesSeeder::class);
});

/** pulls the plaintext confirm-link token out of the queued new-address notification */
function captureEmailChangeToken(User $user): string
{
    $token = null;
    Notification::assertSentOnDemand(
        CatalogDelivery::class,
        function (CatalogDelivery $n, $notifiable, $channel) use (&$token) {
            if ($n->type !== 'auth.email_change' || ($n->data['variant'] ?? null) !== 'to_new') {
                return false;
            }

            $token = basename((string) parse_url((string) $n->data['url'], PHP_URL_PATH));

            return true;
        },
    );

    return (string) $token;
}

function profileUser(): array
{
    $user = User::factory()->create(['email' => 'ada@example.com', 'password' => 'Str0ng!Passw0rd']);

    return [$user, $user->createToken('web', ['*'])->plainTextToken];
}

/* ─────────────────────────── GET/PUT profile ───────────────────────────── */

test('GET profile exposes identity + personalization defaults', function () {
    [$user, $token] = profileUser();

    $this->withToken($token)->getJson('/api/v1/profile')->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.locale', 'en')
        ->assertJsonPath('data.timezone', 'UTC');
});

test('PUT profile updates name/locale/timezone with validation', function () {
    [, $token] = profileUser();

    $this->withToken($token)->putJson('/api/v1/profile', [
        'name' => 'Ada L.', 'locale' => 'fr', 'timezone' => 'Europe/Paris',
    ])->assertOk()->assertJsonPath('data.name', 'Ada L.');

    $fresh = User::first();
    expect($fresh->locale)->toBe('fr')->and($fresh->timezone)->toBe('Europe/Paris');

    $this->withToken($token)->putJson('/api/v1/profile', ['name' => 'x', 'timezone' => 'Mars/Olympus'])
        ->assertStatus(422)->assertJsonValidationErrors(['name', 'timezone']);
});

/* ───────────────────────── AC-001.21 email change ──────────────────────── */

test('email change: request stages a confirm link, notifies both addresses, does NOT change email yet', function () {
    Notification::fake();
    [$user, $token] = profileUser();

    $this->withToken($token)->putJson('/api/v1/profile/email', ['email' => 'ada2@example.com', 'password' => 'Str0ng!Passw0rd'])
        ->assertStatus(202)->assertExactJson(['data' => ['accepted' => true]]);

    expect($user->refresh()->email)->toBe('ada@example.com');
    Notification::assertSentOnDemand(CatalogDelivery::class, fn (CatalogDelivery $n) => $n->type === 'auth.email_change' && ($n->data['variant'] ?? null) === 'to_new');
    Notification::assertSentTo($user, CatalogDelivery::class, fn (CatalogDelivery $n) => $n->type === 'auth.email_change' && ($n->data['variant'] ?? null) === 'to_old');

    $captured = null;
    Notification::assertSentOnDemand(
        CatalogDelivery::class,
        function (CatalogDelivery $n) use (&$captured) {
            if ($n->type !== 'auth.email_change' || ($n->data['variant'] ?? null) !== 'to_new') {
                return false;
            }

            $captured = [basename((string) parse_url((string) $n->data['url'], PHP_URL_PATH)), (string) $n->data['to']];

            return true;
        },
    );
    expect($captured[1])->toBe('ada2@example.com');
});

test('email change: wrong password 422, same email 422', function () {
    [, $token] = profileUser();

    $this->withToken($token)->putJson('/api/v1/profile/email', ['email' => 'x@example.com', 'password' => 'Wr0ng!Password1'])
        ->assertStatus(422)->assertJsonValidationErrors('password');
    $this->withToken($token)->putJson('/api/v1/profile/email', ['email' => 'ada@example.com', 'password' => 'Str0ng!Passw0rd'])
        ->assertStatus(422)->assertJsonValidationErrors('email');
});

test('email change: confirm applies email+verified, revokes ALL sessions, fires event, single-use', function () {
    Event::fake([EmailChanged::class]);
    Notification::fake();
    [$user, $token] = profileUser();
    $this->withToken($token)->putJson('/api/v1/profile/email', ['email' => 'ada2@example.com', 'password' => 'Str0ng!Passw0rd']);
    $linkToken = captureEmailChangeToken($user);

    $this->getJson("/api/v1/auth/confirm-email/{$user->id}/{$linkToken}")
        ->assertOk()->assertJsonPath('data.confirmed', true);

    $user->refresh();
    expect($user->email)->toBe('ada2@example.com')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->tokens()->count())->toBe(0);
    Event::assertDispatched(EmailChanged::class);

    $this->flushHeaders();
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/profile')->assertUnauthorized();
    $this->getJson("/api/v1/auth/confirm-email/{$user->id}/{$linkToken}")->assertForbidden();
});

test('email change: newest request wins — older staged link is dead; foreign token 403', function () {
    Notification::fake();
    [$user, $token] = profileUser();
    $this->withToken($token)->putJson('/api/v1/profile/email', ['email' => 'first@example.com', 'password' => 'Str0ng!Passw0rd']);
    $stale = captureEmailChangeToken($user);
    $this->withToken($token)->putJson('/api/v1/profile/email', ['email' => 'second@example.com', 'password' => 'Str0ng!Passw0rd']);

    $this->getJson("/api/v1/auth/confirm-email/{$user->id}/{$stale}")->assertForbidden();
    expect($user->refresh()->email)->toBe('ada@example.com');

    $fresh = captureEmailChangeToken($user);
    $other = User::factory()->create();
    $this->getJson("/api/v1/auth/confirm-email/{$other->id}/{$fresh}")->assertForbidden();
});

test('email-change links are killed by a password reset (stolen-session protection)', function () {
    Notification::fake();
    [$user, $token] = profileUser();
    $this->withToken($token)->putJson('/api/v1/profile/email', ['email' => 'ada2@example.com', 'password' => 'Str0ng!Passw0rd']);
    $linkToken = captureEmailChangeToken($user);

    $resetToken = Password::createToken($user);
    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'ada@example.com', 'token' => $resetToken,
        'password' => 'Ev3n!Better1', 'password_confirmation' => 'Ev3n!Better1',
    ])->assertOk();

    $this->getJson("/api/v1/auth/confirm-email/{$user->id}/{$linkToken}")->assertForbidden();
});

/* ─────────────────────── AC-001.9 password change ──────────────────────── */

test('password change verifies current, enforces complexity, keeps own session, kills others, fires event', function () {
    Event::fake([PasswordChanged::class]);
    [$user, $token] = profileUser();
    $user->createToken('mobile', ['*']);

    $this->withToken($token)->putJson('/api/v1/profile/password', [
        'current_password' => 'Wr0ng!Password1', 'password' => 'Br3ak!Proof1', 'password_confirmation' => 'Br3ak!Proof1',
    ])->assertStatus(422)->assertJsonValidationErrors('current_password');

    $this->withToken($token)->putJson('/api/v1/profile/password', [
        'current_password' => 'Str0ng!Passw0rd', 'password' => 'weak', 'password_confirmation' => 'weak',
    ])->assertStatus(422)->assertJsonValidationErrors('password');

    $this->withToken($token)->putJson('/api/v1/profile/password', [
        'current_password' => 'Str0ng!Passw0rd', 'password' => 'Br3ak!Proof1', 'password_confirmation' => 'Br3ak!Proof1',
    ])->assertOk()->assertExactJson(['data' => ['changed' => true]]);

    expect($user->tokens()->count())->toBe(1);
    Event::assertDispatched(PasswordChanged::class);

    $this->flushHeaders();
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/profile')->assertOk();
    $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'Str0ng!Passw0rd', 'device_type' => 'web'])->assertStatus(401);
    $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'Br3ak!Proof1', 'device_type' => 'web'])->assertOk();
});

/* ──────────────────────── AC-001.22 delete account ─────────────────────── */

test('delete-account requires password, soft-deletes, revokes everything; login blocked generically', function () {
    [$user, $token] = profileUser();
    $user->createToken('mobile', ['*']);

    $this->withToken($token)->postJson('/api/v1/profile/delete-account', ['password' => 'Wr0ng!Password1'])
        ->assertStatus(422)->assertJsonValidationErrors('password');

    $this->withToken($token)->postJson('/api/v1/profile/delete-account', ['password' => 'Str0ng!Passw0rd'])
        ->assertOk()->assertExactJson(['data' => ['deleted' => true]]);

    expect(User::count())->toBe(0)
        ->and(User::withTrashed()->count())->toBe(1)
        ->and($user->tokens()->count())->toBe(0);

    $this->flushHeaders();
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/profile')->assertUnauthorized();

    $unknown = $this->postJson('/api/v1/auth/login', ['email' => 'ghost@example.com', 'password' => 'Str0ng!Passw0rd', 'device_type' => 'web'])->getContent();
    $deleted = $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'Str0ng!Passw0rd', 'device_type' => 'web']);
    $deleted->assertStatus(401);
    expect($deleted->getContent())->toBe($unknown);
});

test('email-change notifications render correct recipients and content', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $link = AuthLink::issue($user, 'confirm_email_change', email: 'ada2@example.com');

    $catalog = app(NotificationCatalog::class);
    $url = rtrim((string) config('app.url'), '/')."/api/v1/auth/confirm-email/{$user->id}/{$link->token}";

    $newMail = $catalog->get('auth.email_change')->mailable(['variant' => 'to_new', 'to' => 'ada2@example.com', 'url' => $url]);
    expect($newMail->envelope()->subject)->toBe('Confirm your new email address')
        ->and($newMail->actionUrl)->toBe($url);

    $oldMail = $catalog->get('auth.email_change')->mailable(['variant' => 'to_old', 'from' => 'ada@example.com', 'to' => 'ada2@example.com']);
    expect($oldMail->envelope()->subject)->toBe('Your email address was changed')
        ->and(implode(' ', $oldMail->buildViewData()['lines']))->toContain('ada2@example.com');

    // the user lane keeps mail+inbox+broadcast; the unconfirmed address gets mail only
    expect((new CatalogDelivery('auth.email_change', []))->via($user))->toBe(['mail', 'database', 'broadcast']);
});

test('confirm-email for nonexistent user is the same generic 403', function () {
    $this->getJson('/api/v1/auth/confirm-email/99999/'.str_repeat('a', 64))
        ->assertForbidden()
        ->assertJsonPath('message', AuthLinkException::GENERIC_MESSAGE);
});
