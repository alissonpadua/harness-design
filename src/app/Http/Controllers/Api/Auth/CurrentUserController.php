<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Data\Auth\CurrentUserData;
use App\Http\Controllers\Controller;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;

final class CurrentUserController extends Controller
{
    #[Response(status: 200, type: 'array{data: array{id: int, name: string, email: string, email_verified_at: string|null, impersonation: array{impersonator_id: int, impersonator_name: string|null, started_at: string|null}|null}}')]
    public function __invoke(Request $request): CurrentUserData
    {
        /** @var User $user */
        $user = $request->user();

        return CurrentUserData::make($user);
    }
}
