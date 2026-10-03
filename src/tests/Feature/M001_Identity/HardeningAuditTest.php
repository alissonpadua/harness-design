<?php

declare(strict_types=1);

use App\Models\AuthLink;
use App\Models\User;
use App\Notifications\EmailChangeNewAddressNotification;
use App\Notifications\EmailChangeOldAddressNotification;
use App\Notifications\EmailVerificationNotification;
use App\Notifications\MagicLinkNotification;
use App\Notifications\ResetPasswordNotification;
use Database\Seeders\RolesSeeder;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\Support\SourceScan;

/* ───────────────────── AC-001.24 throttle coverage (public auth + admin) ── */

test('every public auth-surface and admin route carries a throttle middleware', function () {
    $unguarded = collect(RouteFacade::getRoutes())
        ->filter(fn (Route $r) => str_starts_with($r->uri(), 'api/v1/auth/') || str_starts_with($r->uri(), 'admin/'))
        // public auth endpoints must never be unthrottled; the admin plane must be throttled even when authenticated
        ->reject(fn (Route $r) => str_starts_with($r->uri(), 'api/v1/auth/') && in_array('auth:sanctum', $r->gatherMiddleware(), true))
        ->filter(fn (Route $r) => ! collect($r->gatherMiddleware())->contains(fn (string $m) => str_starts_with($m, 'throttle:')))
        ->map(fn (Route $r) => implode('|', array_diff($r->methods, ['HEAD'])).' '.$r->uri())
        ->values()
        ->all();

    expect($unguarded)->toBe([], 'public auth/admin routes without throttle buckets');
});

test('every named throttle bucket referenced by routes resolves to a defined limiter', function () {
    $names = collect(RouteFacade::getRoutes())
        ->flatMap(fn (Route $r) => $r->gatherMiddleware())
        ->filter(fn (string $m) => str_starts_with($m, 'throttle:'))
        ->map(function (string $m) {
            $arg = explode(',', substr($m, strlen('throttle:')))[0];

            return is_numeric($arg) ? null : $arg;
        })
        ->filter()
        ->unique()
        ->values();

    expect($names)->not->toBeEmpty();

    foreach ($names as $name) {
        expect(RateLimiter::limiter((string) $name))
            ->not->toBeNull("limiter '{$name}' referenced by routes but never defined");
    }
});

test('named throttle buckets live in a single home (AppServiceProvider)', function () {
    $providerSource = (string) file_get_contents(base_path('app/Providers/AppServiceProvider.php'));

    $referenced = collect(RouteFacade::getRoutes())
        ->flatMap(fn (Route $r) => $r->gatherMiddleware())
        ->filter(fn (string $m) => str_starts_with($m, 'throttle:'))
        ->map(function (string $m) {
            $arg = explode(',', substr($m, strlen('throttle:')))[0];

            return is_numeric($arg) ? null : $arg;
        })
        ->filter()
        ->unique();

    foreach ($referenced as $name) {
        expect($providerSource)->toContain("RateLimiter::for('{$name}'");
    }

    // no other file may define named limiters (single source of truth)
    $strays = collect(SourceScan::projectPhpFiles(['app']))
        ->reject(fn (string $f) => str_ends_with($f, 'AppServiceProvider.php'))
        ->filter(fn (string $f) => str_contains((string) file_get_contents($f), 'RateLimiter::for('))
        ->map(fn (string $f) => ltrim(str_replace(base_path(), '', $f), '/'))
        ->values()
        ->all();

    expect($strays)->toBe([]);
});

/* ─────────────── AC-001.25 notification catalog shape audit ─────────────── */

test('all module-001 notification classes share the mail contract shape', function () {
    $user = User::factory()->create();
    $link = AuthLink::issue($user, 'verify_email');

    $notifications = [
        new EmailVerificationNotification($link),
        new ResetPasswordNotification('tok'),
        new MagicLinkNotification($link),
        new EmailChangeNewAddressNotification($link, 'new@example.com'),
        new EmailChangeOldAddressNotification('new@example.com'),
    ];

    foreach ($notifications as $notification) {
        expect($notification->via($user))->toBe(['mail']);
        $mail = $notification->toMail($user);
        expect($mail->subject)->not->toBeEmpty($notification::class.' has no subject');
        $body = implode(' ', [...$mail->introLines, ...$mail->outroLines]);
        expect($body)->not->toBeEmpty($notification::class.' has no body');
    }
})->group('notifications');

/* ───────────────────── admin surface throttle (from audit) ──────────────── */

test('admin plane responds and stays gated after hardening', function () {
    $this->seed(RolesSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');

    $this->withToken($admin->createToken('cli', ['*'])->plainTextToken)
        ->getJson('/admin/v1/ping')->assertOk();
});
