<?php

declare(strict_types=1);

namespace Tests\Feature\M003_Billing;

use Stripe\HttpClient\ClientInterface;

/**
 * In-process Stripe transport: queues canned [code, json-body] replies and
 * records requests, so StripeGateway logic is unit-tested with zero network.
 */
final class FakeStripeHttp implements ClientInterface
{
    /** @var array<int, array{0: int, 1: array<string, mixed>}> */
    public array $queue = [];

    /** @var array<int, array{method: string, path: string, params: array<string, mixed>}> */
    public array $requests = [];

    /**
     * @param  array<int, array{0: int, 1: array<string, mixed>}>  $queue
     */
    public function __construct(array $queue = [])
    {
        $this->queue = $queue;
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['method' => $method, 'path' => parse_url($absUrl, PHP_URL_PATH) ?: '', 'params' => (array) $params];

        if ($this->queue === []) {
            throw new \RuntimeException('FakeStripeHttp queue empty');
        }

        [$code, $body] = array_shift($this->queue);

        return [json_encode($body, JSON_THROW_ON_ERROR), $code, ['request-id' => 'req_fake', 'content-type' => 'application/json']];
    }

    /**
     * @return array{method: string, path: string, params: array<string, mixed>}
     */
    public function last(): array
    {
        return $this->requests[count($this->requests) - 1];
    }
}
