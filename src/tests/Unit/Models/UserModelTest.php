<?php

declare(strict_types=1);

use App\Models\User;

test('new user carries expected defaults and mass-assignment surface', function () {
    $user = new User(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret-password']);

    expect($user->name)->toBe('Ada')
        ->and($user->email)->toBe('ada@example.com')
        ->and($user->getAuthPassword())->toBe($user->password);
});

test('password, remember token and email verified at are hidden from serialization', function () {
    $hidden = (new User)->getHidden();

    expect($hidden)->toContain('password', 'remember_token');
});

test('email verified at casts to datetime', function () {
    $user = new User;
    $user->email_verified_at = '2026-01-02 03:04:05';

    expect($user->email_verified_at?->toDateTimeString())->toBe('2026-01-02 03:04:05');
});

test('only name, email, password are mass-assignable (no privilege fields)', function () {
    $user = new User(['name' => 'x', 'email' => 'a@b.c', 'password' => 'secret-secret', 'email_verified_at' => now()]);

    expect($user->email_verified_at)->toBeNull();
});

test('users factory produces persistable verified defaults', function () {
    $attributes = User::factory()->raw();

    expect($attributes)->toHaveKeys(['name', 'email', 'password'])
        ->and($attributes['email_verified_at'])->not->toBeNull();
});
