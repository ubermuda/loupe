<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class GithubPrimaryEmailFetcher
{
    public function __construct(
        private HttpClientInterface $githubApiClient,
    ) {
    }

    /**
     * GitHub's /user payload carries only the PUBLIC profile email (possibly null
     * or a vanity address) and no verified flag, so the verified primary email
     * must come from GET /user/emails (requires the user:email scope).
     *
     * @return array{email: string, verified: bool}|null null = could not determine (treat as unverified)
     */
    public function fetchPrimary(string $accessToken): ?array
    {
        try {
            $response = $this->githubApiClient->request('GET', '/user/emails', [
                'headers' => ['Authorization' => 'Bearer '.$accessToken],
            ]);
            // getStatusCode() does not throw on 4xx/5xx, so gate on it before
            // toArray() — a revoked token must degrade, not abort the login.
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                return null;
            }
            $entries = $response->toArray();
        } catch (TransportExceptionInterface|DecodingExceptionInterface) {
            return null;
        }

        foreach ($entries as $entry) {
            if (!is_array($entry) || true !== ($entry['primary'] ?? false) || !is_string($entry['email'] ?? null)) {
                continue;
            }

            return ['email' => $entry['email'], 'verified' => true === ($entry['verified'] ?? false)];
        }

        return null;
    }
}
