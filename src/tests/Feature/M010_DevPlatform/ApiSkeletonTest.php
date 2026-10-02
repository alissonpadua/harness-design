<?php

declare(strict_types=1);

use App\Resources\PongResource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

test('AC-010.7 GET /api/v1/ping is public and returns the data envelope', function () {
    $this->getJson('/api/v1/ping')
        ->assertOk()
        ->assertExactJson(['data' => ['pong' => true]]);
});

test('AC-010.8 unknown /api/v1 path returns 404 JSON envelope', function () {
    $this->getJson('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertJsonPath('message', 'Not Found')
        ->assertHeader('Content-Type', 'application/json');
});

test('AC-010.8 wrong method returns 405 JSON envelope', function () {
    $this->postJson('/api/v1/ping')
        ->assertStatus(405)
        ->assertJsonPath('message', 'Method Not Allowed')
        ->assertHeader('Content-Type', 'application/json');
});

test('AC-010.8 validation failure returns 422 message+errors envelope', function () {
    Route::post('/api/v1/__t-envelope', function (EnvelopeProbeRequest $request) {
        return response()->json(['data' => $request->validated()]);
    });

    $this->postJson('/api/v1/__t-envelope', [])->assertStatus(422)
        ->assertJsonPath('message', 'The email field is required.')
        ->assertJsonStructure(['message', 'errors' => ['email']]);
});

test('AC-010.8 unauthenticated protected route returns 401 JSON envelope', function () {
    // guard will switch to sanctum in spec 001; envelope behavior is guard-agnostic
    Route::get('/api/v1/__t-secure', fn () => 'x')->middleware('auth:web');

    $this->getJson('/api/v1/__t-secure')->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthenticated.');
});

test('AC-010.8 throttled route returns 429 with Retry-After', function () {
    // unique limiter bucket per run so the redis key is never shared across test executions
    Route::get('/api/v1/__t-throttle', fn () => 'ok')->middleware('throttle:2,1,t'.Str::uuid());

    $this->getJson('/api/v1/__t-throttle')->assertOk();

    // Keep hitting until the limiter trips (robust to Laravel's off-by-one across versions).
    $status = 200;
    for ($i = 0; $i < 10 && $status !== 429; $i++) {
        $status = $this->getJson('/api/v1/__t-throttle')->getStatusCode();
    }

    expect($status)->toBe(429);

    $this->getJson('/api/v1/__t-throttle')
        ->assertStatus(429)
        ->assertJsonPath('message', 'Too Many Requests')
        ->assertHeader('Retry-After');
});

test('AC-010.8 error responses carry X-Request-Id', function () {
    $response = $this->getJson('/api/v1/does-not-exist');
    expect($response->headers->get('X-Request-Id'))->not->toBeEmpty();
});

test('AC-010.8 web (non-api) unknown path still returns HTML 404', function () {
    $this->get('/definitely-not-api')->assertStatus(404)
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8');
});

test('success envelope helper wraps arrays under data', function () {
    Route::get('/api/v1/__t-wrap', fn () => ['pong' => true]);
    $this->getJson('/api/v1/__t-wrap')->assertExactJson(['data' => ['pong' => true]]);
});

test('resource-shaped success is returned as-is', function () {
    Route::get('/api/v1/__t-res', fn () => new PongResource(['pong' => true]));
    $this->getJson('/api/v1/__t-res')->assertExactJson(['data' => ['pong' => true]]);
});

final class EnvelopeProbeRequest extends FormRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email']];
    }
}
