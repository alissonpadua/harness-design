<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\VerifyEmailAction;
use App\Data\Auth\EmailVerifiedData;
use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Response;

final class VerifyEmailController extends Controller
{
    #[Response(status: 200, description: 'Email verified.', type: 'array{data: array{verified: bool}}')]
    #[Response(status: 403, description: 'Link invalid, expired or already used.', type: 'array{message: string}')]
    public function __invoke(VerifyEmailAction $action, int $userId, string $token): EmailVerifiedData
    {
        $action->handle($userId, $token);

        return new EmailVerifiedData(true);
    }
}
