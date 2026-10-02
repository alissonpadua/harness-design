<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final readonly class ApiErrorRenderer
{
    public static function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*', 'admin/*')) {
            return null;
        }

        [$status, $message, $errors] = self::normalize($exception);

        $payload = ['message' => $message] + ($errors !== [] ? ['errors' => $errors] : []);

        $response = new JsonResponse($payload, $status);

        if ($request->attributes->has('request_id')) {
            $response->headers->set('X-Request-Id', (string) $request->attributes->get('request_id'));
        }

        if ($exception instanceof HttpExceptionInterface) {
            foreach ($exception->getHeaders() as $name => $value) {
                $response->headers->set($name, (string) $value);
            }
        }

        return $response;
    }

    /**
     * @return array{0:int,1:string,2:array<string,mixed>}
     */
    private static function normalize(Throwable $exception): array
    {
        return match (true) {
            $exception instanceof ValidationException => [
                422, self::firstMessage($exception->getMessage(), 'The given data was invalid.'), $exception->errors(),
            ],
            $exception instanceof AuthenticationException => [401, 'Unauthenticated.', []],
            $exception instanceof AuthorizationException => [403, 'This action is unauthorized.', []],
            $exception instanceof ModelNotFoundException => [404, 'Not Found', []],
            default => self::fromHttp($exception),
        };
    }

    /**
     * @return array{0:int,1:string,2:array<string,mixed>}
     */
    private static function fromHttp(Throwable $exception): array
    {
        if (! $exception instanceof HttpExceptionInterface) {
            $status = 500;
            $message = config('app.debug') && $exception->getMessage() !== ''
                ? $exception->getMessage()
                : 'Internal Server Error';

            return [$status, $message, []];
        }

        $status = $exception->getStatusCode();
        $message = HttpResponse::$statusTexts[$status] ?? 'Error';

        return [$status, $message, []];
    }

    private static function firstMessage(string $message, string $fallback): string
    {
        return $message !== '' ? $message : $fallback;
    }
}
