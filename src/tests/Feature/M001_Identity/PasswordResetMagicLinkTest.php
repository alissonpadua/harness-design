<?php

declare(strict_types=1);

use App\Events\Auth\OtherLoginDetected;
use App\Events\Auth\PasswordChanged;
use App\Models\AuthLink;
use App\Models\User;
use App\Notifications\MagicLinkNotification;
use App\Notifications\ResetPasswordNotification;
use Database\Seeders\RolesSeeder;
use Illuminate\Auth\Notifications\ResetPassword as LaravelResetNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    Notification::fake();
});

function activeUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'email' => 'ada@example.com',
        'password' => 'Str0ng!Passw0rd',
    ], $overrides));
}

function loginWeb(User $user): string
{
    return $user->createToken('web', ['*'])->plainTextToken;
}

/* ─────────────────────────── AC-001.10 forgot ──────────────────────────── */

test('AC-001.10 forgot-password always 202 identical, mails only live accounts', function () {
    $user = activeUser();
    $ghostBody = ['data' => ['accepted' => true]];

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@example.com'])
        ->assertStatus(202)->assertExactJson($ghostBody);
    Notification::assertNothingSent();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ada@example.com'])
        ->assertStatus(202)->assertExactJson($ghostBody);
    Notification::assertSentTo($user, ResetPasswordNotification::class);

    $deleted = activeUser(['email' => 'gone@example.com']);
    $deleted->delete();
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'gone@example.com'])->assertStatus(202);
    Notification::assertNothingSentTo($deleted);

    // framework default notification must never leak through
    Notification::assertSentTo($user, ResetPasswordNotification::class);
    Notification::assertNotSentTo($user, LaravelResetNotification::class);
});

/* ─────────────────────────── AC-001.10 reset ───────────────────────────── */

test('AC-001.10 reset rotates password, revokes all tokens and outstanding links', function () {
    Event::fake([PasswordChanged::class]);
    $user = activeUser();
    $token = loginWeb($user);
    $user->createToken('mobile', ['*']);
    $verifyLink = AuthLink::issue($user, 'verify_email');
    $magicLink = AuthLink::issue($user, 'magic_link');

    $resetToken = Password::createToken($user);

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'ada@example.com',
        'token' => $resetToken,
        'password' => 'N3w!SecretPass',
        'password_confirmation' => 'N3w!SecretPass',
    ])->assertOk()->assertExactJson(['data' => ['reset' => true]]);

    expect(password_verify('N3w!SecretPass', $user->refresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0)
        ->and($verifyLink->refresh()->used_at)->not->toBeNull()
        ->and($magicLink->refresh()->used_at)->not->toBeNull();

    Event::assertDispatched(PasswordChanged::class);

    // replay the (now deleted) reset token + old device token both dead
    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'ada@example.com', 'token' => $resetToken,
        'password' => 'N3w!SecretPass', 'password_confirmation' => 'N3w!SecretPass',
    ])->assertStatus(422)->assertJsonValidationErrors('token');
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

test('AC-001.10 reset: unknown email and bogus token give byte-identical 422', function () {
    activeUser();

    $bogus = $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'ada@example.com', 'token' => str_repeat('a', 64),
        'password' => 'N3w!SecretPass', 'password_confirmation' => 'N3w!SecretPass',
    ]);
    $ghost = $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'ghost@example.com', 'token' => str_repeat('a', 64),
        'password' => 'N3w!SecretPass', 'password_confirmation' => 'N3w!SecretPass',
    ]);

    $bogus->assertStatus(422)->assertJsonPath('errors.token.0', 'This password reset token is invalid.');
    expect($ghost->getContent())->toBe($bogus->getContent());
});

test('AC-001.10 reset enforces complexity on the new password', function () {
    $user = activeUser();
    $resetToken = Password::createToken($user);

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'ada@example.com', 'token' => $resetToken,
        'password' => 'weak', 'password_confirmation' => 'weak',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});

test('AC-001.10 reset notification renders token and subject', function () {
    $user = activeUser();
    $notification = new ResetPasswordNotification('abc123token');

    expect($notification->via($user))->toBe(['mail']);
    $mail = $notification->toMail($user);
    expect($mail->subject)->toBe('Reset your password')
        ->and(implode(' ', [...$mail->introLines, ...$mail->outroLines]))->toContain('abc123token');
});

/* ────────────────────────── AC-001.11 magic link ───────────────────────── */

test('AC-001.11 magic request is generic 202 and mails only live accounts with 15-min links', function () {
    $user = activeUser();

    $this->postJson('/api/v1/auth/magic-link/request', ['email' => 'ghost@example.com'])
        ->assertStatus(202)->assertExactJson(['data' => ['accepted' => true]]);
    Notification::assertNothingSent();

    $this->postJson('/api/v1/auth/magic-link/request', ['email' => 'ada@example.com'])->assertStatus(202);
    Notification::assertSentTo($user, MagicLinkNotification::class, function (MagicLinkNotification $n) {
        return $n->link->expires_at->between(now()->addMinutes(14), now()->addMinutes(16));
    });
});

test('AC-001.11 consume issues device token, verifies email, single-use, same-type replace', function () {
    Event::fake([OtherLoginDetected::class]);
    $user = activeUser(['email_verified_at' => null]);
    $mobileIssued = $user->createToken('mobile', ['*']);
    $mobileIssued->accessToken->forceFill(['device_type' => 'mobile'])->save();
    $mobileToken = $mobileIssued->plainTextToken;
    $link = AuthLink::issue($user, 'magic_link');
    $rawToken = $link->token; // capture before refresh() drops the transient attribute

    $response = $this->postJson('/api/v1/auth/magic-link/consume', [
        'token' => $rawToken,
        'device_type' => 'web',
    ]);

    $response->assertOk()->assertJsonStructure(['data' => ['token', 'device_type']]);
    expect($response->json('data.device_type'))->toBe('web')
        ->and($user->refresh()->email_verified_at)->not->toBeNull()
        ->and($link->refresh()->used_at)->not->toBeNull();

    // other device survived, and we learned about it
    Event::assertDispatched(OtherLoginDetected::class);
    $this->withToken($mobileToken)->getJson('/api/v1/auth/me')->assertOk();

    // replay
    $this->postJson('/api/v1/auth/magic-link/consume', ['token' => $rawToken, 'device_type' => 'web'])
        ->assertForbidden()
        ->assertJsonPath('message', 'This verification link is no longer valid.');
});

test('AC-001.11 consume rejects expired, bogus, and deleted-owner links identically', function () {
    $user = activeUser();
    $expired = AuthLink::issue($user, 'magic_link', minutes: -1);
    $gone = activeUser(['email' => 'gone@example.com']);
    $deadLink = AuthLink::issue($gone, 'magic_link');
    $gone->delete();

    $bodies = collect([$expired->token, str_repeat('f', 64), $deadLink->token])
        ->map(fn (string $t) => $this->postJson('/api/v1/auth/magic-link/consume', ['token' => $t, 'device_type' => 'cli'])->getContent());

    expect($bodies[0])->toBe($bodies[1])->and($bodies[1])->toBe($bodies[2]);
    $this->postJson('/api/v1/auth/magic-link/consume', ['token' => $expired->token, 'device_type' => 'cli'])->assertForbidden();
});

test('AC-001.11 consume validates device_type before touching links', function () {
    $this->postJson('/api/v1/auth/magic-link/consume', ['token' => 'whatever', 'device_type' => 'toaster'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('device_type');
});

test('AC-001.11 magic notification renders token', function () {
    $user = activeUser();
    $link = AuthLink::issue($user, 'magic_link');
    $notification = new MagicLinkNotification($link);

    expect($notification->via($user))->toBe(['mail']);
    $mail = $notification->toMail($user);
    expect($mail->subject)->toBe('Your sign-in link')
        ->and(implode(' ', [...$mail->introLines, ...$mail->outroLines]))->toContain($link->token);
});
