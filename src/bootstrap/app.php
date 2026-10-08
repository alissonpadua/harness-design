<?php

declare(strict_types=1);

use App\Exceptions\ApiErrorRenderer;
use App\Http\Middleware\ApiEnvelope;
use App\Http\Middleware\AuditImpersonatedRequest;
use App\Http\Middleware\EnsureDocsVisible;
use App\Http\Middleware\EnsureNotImpersonating;
use App\Http\Middleware\EnsureNotSuspended;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\TrackTokenUsage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            require __DIR__.'/../routes/health.php';
        },
    )
    // Reverb proxies private-channel auth here (spec 004 S3).
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['auth:sanctum']],
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('billing:dunning')->hourly()->withoutOverlapping();
        $schedule->command('notifications:sweep-failed')->everyFifteenMinutes()->withoutOverlapping();
        $schedule->command('notifications:trial-reminders')->dailyAt('08:00')->withoutOverlapping();
        $schedule->command('audit:prune')->dailyAt('03:00')->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append([
            RequestId::class,
            ApiEnvelope::class,
        ]);

        $middleware->alias([
            'not-suspended' => EnsureNotSuspended::class,
            'not-impersonating' => EnsureNotImpersonating::class,
            'audit-impersonated' => AuditImpersonatedRequest::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        $middleware->api(append: [
            TrackTokenUsage::class,
        ]);

        $middleware->web(append: [
            EnsureDocsVisible::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'admin/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (Throwable $e, Request $request) => ApiErrorRenderer::render($e, $request));
    })->create();
