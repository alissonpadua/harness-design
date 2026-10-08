<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\SetRegistrationsOpenAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateRegistrationsRequest;
use App\Settings\RegistrationsSettings;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;

final class SettingsController extends Controller
{
    public function __construct(private readonly SetRegistrationsOpenAction $set) {}

    public function registrations(RegistrationsSettings $settings): JsonResponse
    {
        return response()->json(['data' => ['open' => $settings->open]]);
    }

    public function updateRegistrations(UpdateRegistrationsRequest $form): JsonResponse
    {
        $actor = $form->user()
            ?? throw new AuthenticationException;

        $open = $this->set->handle($actor, (bool) $form->validated('open'));

        return response()->json(['data' => ['open' => $open]]);
    }
}
