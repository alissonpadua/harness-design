<?php

declare(strict_types=1);

namespace App\Actions;

final readonly class PingAction
{
    /**
     * @return array{pong: bool}
     */
    public function handle(): array
    {
        return ['pong' => true];
    }
}
