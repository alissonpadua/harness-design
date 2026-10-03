<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Profile\ConfirmEmailChangeAction;
use App\Data\Profile\ConfirmEmailData;
use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Response;

final class ConfirmEmailController extends Controller
{
    #[Response(status: 200, description: 'Email changed; all sessions revoked.', type: 'array{data: array{confirmed: bool}}')]
    #[Response(status: 403, description: 'Unknown/expired/consumed link (identical).', type: 'array{message: string}')]
    public function __invoke(ConfirmEmailChangeAction $action, int $userId, string $token): ConfirmEmailData
    {
        $action->handle($userId, $token);

        return new ConfirmEmailData(true);
    }
}
