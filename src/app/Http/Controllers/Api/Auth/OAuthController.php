<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\CompleteOAuthAction;
use App\Data\Auth\CompleteOAuthData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\NoBodyRequest;
use App\Http\Requests\Auth\OAuthExchangeRequest;
use Illuminate\Http\JsonResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OAuthController extends Controller
{
    public function redirect(NoBodyRequest $request, string $provider): JsonResponse
    {
        $this->assertProvider($provider);

        // stateless(): token/API flow — the client keeps OAuth state, not the session.
        $driver = Socialite::driver($provider);
        assert($driver instanceof AbstractProvider);

        return response()->json(['data' => ['url' => $driver->stateless()->redirect()->getTargetUrl()]]);
    }

    public function exchange(OAuthExchangeRequest $request, CompleteOAuthAction $action, string $provider): Response
    {
        $data = CompleteOAuthData::from([
            'provider' => $provider,
            ...$request->validated(),
        ]);

        $result = $action->handle($data, (string) $request->ip(), $request->userAgent());

        return $result->toResponse($request)->setStatusCode(200);
    }

    private function assertProvider(string $provider): void
    {
        if (! in_array($provider, config('auth.oauth.providers'), true)) {
            throw new NotFoundHttpException;
        }
    }
}
