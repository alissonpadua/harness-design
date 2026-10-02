<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

function registerThrowingApiRoute(string $uri, callable $handler): void
{
    Route::get("/api/v1/{$uri}", $handler);
}

test('404: ModelNotFoundException renders Not Found envelope', function () {
    registerThrowingApiRoute('__e-modelnotfound', function () {
        throw new ModelNotFoundException('No query results for model.');
    });

    $this->getJson('/api/v1/__e-modelnotfound')
        ->assertNotFound()
        ->assertJsonPath('message', 'Not Found');
});

test('403: AuthorizationException renders unauthorized envelope', function () {
    registerThrowingApiRoute('__e-denied', function () {
        throw new AuthorizationException;
    });

    $this->getJson('/api/v1/__e-denied')
        ->assertForbidden()
        ->assertJsonPath('message', 'This action is unauthorized.');
});

test('500 on api paths: generic message with debug off, real message with debug on', function () {
    config(['app.debug' => false]);
    registerThrowingApiRoute('__e-boom', function () {
        throw new RuntimeException('internal leak detail');
    });

    $this->getJson('/api/v1/__e-boom')
        ->assertStatus(500)
        ->assertExactJson(['message' => 'Internal Server Error']);

    config(['app.debug' => true]);
    $this->getJson('/api/v1/__e-boom')
        ->assertStatus(500)
        ->assertJsonPath('message', 'internal leak detail');
});

test('validation exception with empty message falls back to generic text', function () {
    registerThrowingApiRoute('__e-validation', function () {
        $exception = ValidationException::withMessages(['name' => ['The name field is required.']]);
        (function () {
            $this->message = '';
        })->call($exception);

        throw $exception;
    });

    $this->getJson('/api/v1/__e-validation')
        ->assertStatus(422)
        ->assertJsonPath('message', 'The given data was invalid.')
        ->assertJsonPath('errors.name.0', 'The name field is required.');
});
