<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Auth\TotpProvisioner;
use App\Data\Auth\TwoFactorEnrollData;
use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

final readonly class EnrollTwoFactorAction
{
    public function __construct(
        private Google2FA $google2fa,
        private TotpProvisioner $provisioner,
    ) {}

    public function handle(User $user): TwoFactorEnrollData
    {
        $secret = $this->google2fa->generateSecretKey(32);

        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null])->save();

        $uri = $this->provisioner->provisioningUri($user, $secret);

        return new TwoFactorEnrollData(
            secret: $secret,
            provisioning_uri: $uri,
            qr_data_url: $this->provisioner->qrDataUrl($uri),
        );
    }
}
