<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Stripe\HttpClient\ClientInterface;

/**
 * Stripe HTTP client that answers every call with a canned body and records the
 * request it was given. Installed with `ApiRequestor::setHttpClient()` (a
 * process-global hook — always reset it in tearDown), it lets the gateway's
 * request shapes be asserted without any network access.
 */
final class RecordingStripeHttpClient implements ClientInterface
{
    /** @var list<array{method: string, url: string, params: array<string, mixed>, headers: list<string>}> */
    public array $requests = [];

    /** @param array<string, mixed> $body */
    public function __construct(
        private readonly array $body,
    ) {
    }

    /**
     * @param string               $method
     * @param string               $absUrl
     * @param array<mixed>         $headers
     * @param array<string, mixed> $params
     * @param bool                 $hasFile
     * @param string               $apiMode
     * @param ?int                 $maxNetworkRetries
     *
     * @return array{string, int, array<mixed>}
     */
    #[\Override]
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params, 'headers' => array_values($headers)];

        return [json_encode($this->body, JSON_THROW_ON_ERROR), 200, []];
    }
}
