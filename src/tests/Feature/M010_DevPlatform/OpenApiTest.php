<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

test('AC-010.5 openapi.json exists and is committed', function () {
    expect(base_path('openapi.json'))->toBeFile();
});

test('AC-010.5 every API route appears in the OpenAPI document', function () {
    $doc = json_decode((string) file_get_contents(base_path('openapi.json')), true, 512, JSON_THROW_ON_ERROR);

    // Documented operations: server path prefix (e.g. /api) + path (e.g. /v1/ping).
    $documented = collect($doc['paths'] ?? [])
        ->flatMap(function (array $methods, string $path) use ($doc) {
            $prefixes = collect($doc['servers'] ?? [])
                ->map(fn (array $s) => rtrim((string) parse_url((string) $s['url'], PHP_URL_PATH), '/'))
                ->whenEmpty(fn ($c) => $c->push(''));

            return $prefixes->flatMap(fn (string $prefix) => array_map(
                fn (string $m) => strtoupper($m).' '.ltrim($prefix.$path, '/'),
                array_keys(array_diff_key($methods, ['parameters' => 1, 'servers' => 1]))
            ))->all();
        })
        ->all();

    $appRoutes = collect(RouteFacade::getRoutes())
        ->filter(fn (Route $r) => str_starts_with($r->uri(), 'api/') || str_starts_with($r->uri(), 'admin/'))
        ->flatMap(fn (Route $r) => array_map(
            fn (string $m) => $m.' '.$r->uri(),
            array_diff($r->methods, ['HEAD'])
        ))
        ->all();

    $missing = array_values(array_diff($appRoutes, $documented));

    expect($missing)->toBe([], 'Undocumented API routes found — run: php artisan scramble:export --path=openapi.json && commit openapi.json (host: src/bin/scramble)');
});

test('AC-010.5 docs surface hidden by default, visible when DOCS_PUBLIC', function () {
    config(['app.docs_public' => false]);
    $this->get('/docs')->assertNotFound();
    $this->get('/docs/api')->assertNotFound();

    config(['app.docs_public' => true]);
    $this->get('/docs')->assertRedirect('/docs/api');
    $this->getJson('/docs/api.json')
        ->assertOk()
        ->assertJsonPath('openapi', '3.1.0');
});
