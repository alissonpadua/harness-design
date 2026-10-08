<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Org;

use App\Actions\Org\CreateIntegrationTokenAction;
use App\Actions\Org\RevokeIntegrationTokenAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Org\IntegrationTokenIndexRequest;
use App\Http\Requests\Org\StoreIntegrationTokenRequest;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

#[Group('Organization')]
final class IntegrationTokenController extends Controller
{
    public function __construct(
        private readonly CreateIntegrationTokenAction $create,
        private readonly RevokeIntegrationTokenAction $revoke,
    ) {}

    public function index(IntegrationTokenIndexRequest $form): JsonResponse
    {
        $org = $form->organization();

        $rows = $org->integrationTokens()
            ->get(['id', 'name', 'abilities', 'last_used_at', 'created_at'])
            ->map(fn ($t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'abilities' => $t->abilities,
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'created_at' => $t->created_at?->toIso8601String(),
            ])
            ->all();

        return response()->json(['data' => ['tokens' => $rows]]);
    }

    public function store(StoreIntegrationTokenRequest $form, string $organization): JsonResponse
    {
        /** @var User $creator */
        $creator = $form->user();
        $org = $form->organization();

        $result = $this->create->handle(
            $creator,
            $org,
            (string) $form->validated('name'),
            (array) $form->validated('abilities'),
        );

        return response()->json(['data' => $result], 201);
    }

    public function destroy(IntegrationTokenIndexRequest $form, int $organization, int $tokenId): Response
    {
        unset($organization); // resolved (and authorized) by the FormRequest via {organization}
        /** @var User $actor */
        $actor = $form->user();

        $this->revoke->handle($actor, $form->organization(), $tokenId);

        return response()->noContent();
    }
}
