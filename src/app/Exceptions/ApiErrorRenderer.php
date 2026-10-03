<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
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
            $exception instanceof LoginFailedException => [401, 'These credentials do not match our records.', []],
            $exception instanceof EmailNotVerifiedException => [403, 'Please verify your email address.', []],
            $exception instanceof TwoFactorRequiredException => [401, 'Two factor authentication is required.', []],
            $exception instanceof TwoFactorMandatoryException => [403, 'Two factor authentication is mandatory for your organization.', ['two_factor' => ['required']]],
            $exception instanceof AuthLinkException => [403, AuthLinkException::GENERIC_MESSAGE, []],
            $exception instanceof SubscriptionRequiredException => [402, 'Subscription required.', []],
            $exception instanceof OrgTokenException => [403, OrgTokenException::MESSAGE, []],
            $exception instanceof LastOwnerException => [409, 'The organization must keep an owner.', []],
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

        // framework/spatie authz failures keep the generic message; explicit
        // HttpExceptions (403 gates etc.) carry their own domain message.
        if ($exception instanceof AccessDeniedHttpException || $exception instanceof UnauthorizedException) {
            return [403, 'This action is unauthorized.', []];
        }

        $message = match (true) {
            $status === 404 => 'Not Found',
            $status === 405 => 'Method Not Allowed',
            $status === 429 => 'Too Many Requests',
            $exception->getMessage() !== '' => $exception->getMessage(),
            default => HttpResponse::$statusTexts[$status] ?? 'Error',
        };

        return [$status, $message, []];
    }

    private static function firstMessage(string $message, string $fallback): string
    {
        return $message !== '' ? $message : $fallback;
    }
}
