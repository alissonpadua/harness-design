<?php

declare(strict_types=1);

namespace App\Actions\Org;

use App\Audit\AuditSecurityEvent;
use App\Models\Organization;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class RevokeIntegrationTokenAction
{
    public function __construct(private AuditSecurityEvent $audit) {}

    public function handle(User $actor, Organization $org, int $tokenId): void
    {
        $token = $org->integrationTokens()->whereKey($tokenId)->first()
            ?? throw (new ModelNotFoundException)->setModel(PersonalAccessToken::class, $tokenId);

        $this->audit->log('integration_token_revoked', $actor, $org, ['token_id' => $token->id, 'name' => $token->name]);

        $token->delete();
    }
}
