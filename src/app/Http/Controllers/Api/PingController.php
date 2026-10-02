<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\PingAction;
use App\Data\PongData;
use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Response;

final class PingController extends Controller
{
    public function __construct(private readonly PingAction $action) {}

    // Type notation documents the full envelope incl. Data auto-wrap under "data"
    // (Scramble free; PRO would infer PongData itself).
    #[Response(status: 200, description: 'Standard success envelope.', type: 'array{data: array{pong: bool}}')]
    public function __invoke(): PongData
    {
        return PongData::from($this->action->handle());
    }
}
