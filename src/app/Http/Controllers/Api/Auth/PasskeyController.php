<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\AuthenticatePasskeyAction;
use App\Actions\Auth\CreatePasskeyAssertionOptionsAction;
use App\Actions\Auth\CreatePasskeyOptionsAction;
use App\Actions\Auth\DeletePasskeyAction;
use App\Actions\Auth\RegisterPasskeyAction;
use App\Data\Auth\PasskeyData;
use App\Data\Auth\PasskeyListData;
use App\Enums\DeviceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AuthenticatePasskeyRequest;
use App\Http\Requests\Auth\NoBodyRequest;
use App\Http\Requests\Auth\PasskeyAuthOptionsRequest;
use App\Http\Requests\Auth\RegisterPasskeyRequest;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class PasskeyController extends Controller
{
    #[Response(status: 200, description: 'Creation options for navigator.credentials.create().', type: 'array{data: array{publicKey: object}}')]
    public function registerOptions(NoBodyRequest $request, CreatePasskeyOptionsAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $action->handle($user)]);
    }

    #[Response(status: 201, type: 'array{data: array{id: int, name: string, created_at: string}}')]
    #[Response(status: 422, description: 'Invalid/unmatched attestation or cap reached.', type: 'array{message: string, errors: object}')]
    public function register(RegisterPasskeyRequest $request, RegisterPasskeyAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $key = $action->handle($user, (string) $request->validated('name'), (array) $request->validated('credential'));

        return response()->json(PasskeyData::make($key)->toResponse($request), 201);
    }

    #[Response(status: 200, type: 'array{data: array{passkeys: array<int, array{id: int, name: string, created_at: string}>}}')]
    public function index(Request $request): PasskeyListData
    {
        /** @var User $user */
        $user = $request->user();

        return new PasskeyListData(
            $user->passkeys()->latest()->get()->map(fn ($key) => PasskeyData::make($key))->all()
        );
    }

    #[Response(status: 200, type: 'array{data: array{revoked: bool}}')]
    #[Response(status: 404, description: 'Unknown or foreign passkey.', type: 'array{message: string}')]
    public function destroy(NoBodyRequest $request, DeletePasskeyAction $action, int $passkey): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->handle($user, $passkey);

        return response()->json(['data' => ['revoked' => true]]);
    }

    #[Response(status: 200, description: 'Request options for navigator.credentials.get().', type: 'array{data: array{publicKey: object}}')]
    public function authOptions(PasskeyAuthOptionsRequest $request, CreatePasskeyAssertionOptionsAction $action): JsonResponse
    {
        return response()->json(['data' => $action->handle($request->validated('email'))]);
    }

    #[Response(status: 200, type: 'array{data: array{token: string, device_type: string}}')]
    #[Response(status: 401, description: 'Unknown credential / failed assertion (identical).', type: 'array{message: string}')]
    public function authenticate(AuthenticatePasskeyRequest $request, AuthenticatePasskeyAction $action): SymfonyResponse
    {
        $result = $action->handle(
            (array) $request->validated('credential'),
            DeviceType::from((string) $request->validated('device_type')),
            (string) $request->ip(),
            $request->userAgent(),
        );

        return $result->toResponse($request)->setStatusCode(200);
    }
}
