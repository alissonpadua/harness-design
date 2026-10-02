<?php

declare(strict_types=1);

namespace App\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @param  array{pong: bool}  $resource
 */
class PongResource extends JsonResource
{
    /**
     * @return array{pong: bool}
     */
    public function toArray(Request $request): array
    {
        return ['pong' => $this->resource['pong']];
    }
}
