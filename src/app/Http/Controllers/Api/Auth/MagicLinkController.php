<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\ConsumeMagicLinkAction;
use App\Actions\Auth\RequestMagicLinkAction;
use App\Data\Auth\ConsumeMagicLinkData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConsumeMagicLinkRequest;
use App\Http\Requests\Auth\EmailRequest;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class MagicLinkController extends Controller
{
    #[Response(status: 202, description: 'Always accepted (anti-enumeration).', type: 'array{data: array{accepted: bool}}')]
    public function request(EmailRequest $request, RequestMagicLinkAction $action): JsonResponse
    {
        $action->handle($request->validated('email'));

        return response()->json(['data' => ['accepted' => true]], 202);
    }

    #[Response(status: 200, description: 'Signs in and marks the email verified.', type: 'array{data: array{token: string, device_type: string}}')]
    #[Response(status: 403, description: 'Unknown/expired/consumed link (identical).', type: 'array{message: string}')]
    public function consume(ConsumeMagicLinkRequest $request, ConsumeMagicLinkAction $action): SymfonyResponse
    {
        $result = $action->handle(
            ConsumeMagicLinkData::from($request->validated()),
            (string) $request->ip(),
            $request->userAgent(),
        );

        return $result->toResponse($request)->setStatusCode(200);
    }
}
