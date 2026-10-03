<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

final readonly class TotpProvisioner
{
    public function provisioningUri(User $user, string $secret): string
    {
        $issuer = rawurlencode((string) config('app.name'));
        $label = rawurlencode($user->email);

        return "otpauth://totp/{$issuer}:{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    }

    public function qrDataUrl(string $uri): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(200), new SvgImageBackEnd)))
            ->writeString($uri);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
