<?php

declare(strict_types=1);

use App\Exceptions\ApiErrorRenderer;
use App\Http\Middleware\ApiEnvelope;
use App\Http\Middleware\EnsureDocsVisible;
use App\Http\Middleware\RequestId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append([
            RequestId::class,
            ApiEnvelope::class,
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
