<?php

declare(strict_types=1);

namespace App\Actions\Org;

use App\Audit\AuditSecurityEvent;
use App\Models\Organization;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Spec 006: long-lived, org-owned, ability-scoped PAT. Plaintext is returned
 * EXACTLY ONCE and never persisted readable. 2FA gate = account-level
 * confirmation (Q2=C deviation; passkey counts per Q3=A).
 */
final readonly class CreateIntegrationTokenAction
{
    public function __construct(private AuditSecurityEvent $audit) {}

    /**
     * @param  array<int, string>  $abilities
     * @return array{id: int, name: string, abilities: array<int, string>, token: string}
     */
    public function handle(User $creator, Organization $org, string $name, array $abilities): array
    {
        if (! $creator->hasSecondFactor()) {
            throw new HttpException(403, 'Two-factor authentication is required to create integration tokens.');
        }

        $created = $creator->createToken($name, $abilities);
        $created->accessToken->forceFill([
            'organization_id' => $org->id,
            'kind' => 'integration',
            'device_type' => null,
        ])->save();

        $this->audit->log('integration_token_created', $creator, $created->accessToken, [
            'organization_id' => $org->id, 'name' => $name, 'abilities' => $abilities,
        ]);

        return [
            'id' => $created->accessToken->id,
            'name' => $name,
            'abilities' => $abilities,
            'token' => $created->plainTextToken,
        ];
    }
}
