<?php

declare(strict_types=1);

namespace Tests\Feature\M006_Security;

use App\Logging\RequestIdProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/* ─────────────── AC-006.1 security headers on every response ─────────────── */

test('AC-006.1 api responses carry the locked header set (200, 4xx and 5xx)', function () {
    // 422 validation error surface
    $this->postJson('/api/v1/auth/register', [])->assertStatus(422)
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");

    // 404
    $this->getJson('/api/v1/nope')->assertNotFound()
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    // 401 (auth plane)
    $this->getJson('/api/v1/profile')->assertUnauthorized()
        ->assertHeader('X-Frame-Options', 'DENY');

    // healthy JSON success
    $this->getJson('/api/v1/ping')->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer');

    // HSTS absent over plain http (dev), present over https
    $this->getJson('/api/v1/ping')->assertHeaderMissing('Strict-Transport-Security');
    $this->get('https://localhost/api/v1/ping')->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

test('AC-006.1 /docs is exempt from CSP but keeps the other headers (deviation D3)', function () {
    $this->get('/docs/api')->assertOk()
        ->assertHeaderMissing('Content-Security-Policy')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY');
});

/* ─────────────────── AC-006.2 X-Request-Id semantics ─────────────────────── */

test('AC-006.2 valid incoming request id is reused verbatim; invalid ones replaced', function () {
    $this->getJson('/api/v1/ping', ['X-Request-Id' => 'trace_9-Ab_1'])
        ->assertOk()->assertHeader('X-Request-Id', 'trace_9-Ab_1');

    // bad chars -> regenerated ULID (26 Crockford chars)
    $bad = $this->getJson('/api/v1/ping', ['X-Request-Id' => '<script>xx</script>'])->assertOk();
    $new = $bad->headers->get('X-Request-Id');
    expect($new)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')->not->toBe('<script>xx</script>');

    // over-length -> regenerated, never truncated echo of attacker value
    $long = $this->getJson('/api/v1/ping', ['X-Request-Id' => str_repeat('a', 300)])->assertOk();
    expect($long->headers->get('X-Request-Id'))->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');

    // always present even without incoming header
    expect($this->getJson('/api/v1/ping')->headers->get('X-Request-Id'))->not->toBeEmpty();
});

test('AC-006.2 request id lands in log line context', function () {
    $log = storage_path('logs/m006-probe.log');
    @unlink($log);
    config(['logging.default' => 'm006probe', 'logging.channels.m006probe' => [
        'driver' => 'single', 'path' => $log, 'level' => 'debug', 'replace_placeholders' => true, 'tap' => [RequestIdProcessor::class],
    ]]);

    Route::middleware(['web'])->get('api/v1/__m006_logprobe', function () {
        logger()->info('probe-line');

        return ['ok' => true];
    });

    $this->getJson('/api/v1/__m006_logprobe', ['X-Request-Id' => 'logtrace42'])->assertOk();

    $contents = (string) file_get_contents($log);
    @unlink($log);
    expect($contents)->toContain('probe-line')->toContain('logtrace42');
});

/* ────────────────────── AC-006.3 CORS allowlist ──────────────────────────── */

test('AC-006.3 listed origins get CORS headers; credentials stay off; others get none', function () {
    config(['cors.allowed_origins' => ['https://app.example.test']]);

    // simple request
    $this->getJson('/api/v1/ping', ['Origin' => 'https://app.example.test'])
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', 'https://app.example.test');

    // preflight
    $this->options('/api/v1/profile', [
        'Origin' => 'https://app.example.test',
        'Access-Control-Request-Method' => 'GET',
        'Access-Control-Request-Headers' => 'authorization,content-type',
    ])->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', 'https://app.example.test');

    // never allow credentials (bearer-in-header API)
    expect($this->getJson('/api/v1/ping', ['Origin' => 'https://app.example.test'])
        ->headers->get('Access-Control-Allow-Credentials'))->toBeNull();

    // unlisted origin -> never granted (single-origin static header still
    // fails browser-side comparison; multi-origin config must omit the header)
    $evil = $this->getJson('/api/v1/ping', ['Origin' => 'https://evil.example'])->assertOk();
    expect($evil->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.example');

    config(['cors.allowed_origins' => ['https://app.example.test', 'https://admin.example.test']]);
    $this->getJson('/api/v1/ping', ['Origin' => 'https://evil.example'])
        ->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
    $this->getJson('/api/v1/ping', ['Origin' => 'https://admin.example.test'])
        ->assertOk()->assertHeader('Access-Control-Allow-Origin', 'https://admin.example.test');

    // empty allowlist -> nothing cross-origin gets headers
    config(['cors.allowed_origins' => []]);
    $this->getJson('/api/v1/ping', ['Origin' => 'https://app.example.test'])
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});
