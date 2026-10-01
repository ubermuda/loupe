<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Exception as JwtException;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The calls Loupe makes as the App and as one of its installations. Tokens
 * stay in this object's memory only: a shared cache pool would rest them in
 * the database.
 */
final class GitHubAppApi
{
    private const int PAGE_SIZE = 100;
    private const int MAX_PAGES = 10;

    /** @var array<int, array{token: non-empty-string, until: \DateTimeImmutable}> */
    private array $tokens = [];

    public function __construct(
        private readonly HttpClientInterface $githubApiClient,
        private readonly GitHubAppConfiguration $configuration,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return non-empty-string
     *
     * @throws GitHubAppApiFailed
     */
    public function installationToken(int $installationId): string
    {
        $now = $this->clock->now();
        $cached = $this->tokens[$installationId] ?? null;
        if (null !== $cached && $now < $cached['until']) {
            return $cached['token'];
        }

        $body = $this->send('POST', '/app/installations/'.$installationId.'/access_tokens', []);
        $token = $body['token'] ?? null;
        if (!\is_string($token) || '' === $token) {
            throw new GitHubAppApiFailed('malformed_body');
        }

        // GitHub issues the token for one hour.
        $this->tokens[$installationId] = ['token' => $token, 'until' => $now->modify('+50 minutes')];

        return $token;
    }

    /**
     * @return list<GitHubAppInstallationAccess>
     *
     * @throws GitHubAppApiFailed
     */
    public function installations(): array
    {
        $installations = [];
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $items = $this->send('GET', '/app/installations', ['query' => ['per_page' => self::PAGE_SIZE, 'page' => $page]]);
            if (!array_is_list($items)) {
                throw new GitHubAppApiFailed('malformed_body');
            }

            foreach ($items as $item) {
                $id = \is_array($item) ? ($item['id'] ?? null) : null;
                if (!\is_int($id)) {
                    continue;
                }

                $login = \is_array($item['account'] ?? null) ? ($item['account']['login'] ?? null) : null;
                $permissions = \is_array($item['permissions'] ?? null) ? $item['permissions'] : [];
                $installations[] = new GitHubAppInstallationAccess(
                    $id,
                    \is_string($login) && '' !== $login ? $login : '#'.$id,
                    array_filter($permissions, fn (mixed $grant, mixed $name): bool => \is_string($name) && \is_string($grant), \ARRAY_FILTER_USE_BOTH),
                );
            }

            if (\count($items) < self::PAGE_SIZE) {
                break;
            }
        }

        return $installations;
    }

    /**
     * Answers the `data` member. GitHub reports a missing node as null data
     * beside a `NOT_FOUND` error, so that error alone does not fail the call.
     * Any other error does, because it can null a node that exists.
     *
     * @param array<string, mixed> $variables
     *
     * @return array<mixed>
     *
     * @throws GitHubAppApiFailed
     */
    public function graphql(int $installationId, string $query, array $variables): array
    {
        $body = $this->send('POST', '/graphql', ['json' => ['query' => $query, 'variables' => $variables]], $this->installationToken($installationId));
        $data = $body['data'] ?? null;
        $errors = \is_array($body['errors'] ?? null) ? $body['errors'] : [];
        foreach ($errors as $error) {
            if (!\is_array($error) || 'NOT_FOUND' !== ($error['type'] ?? null)) {
                throw new GitHubAppApiFailed('graphql_error');
            }
        }

        return \is_array($data) ? $data : throw new GitHubAppApiFailed('graphql_error');
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<mixed>
     *
     * @throws GitHubAppApiFailed
     */
    public function get(int $installationId, string $path, array $query = []): array
    {
        return $this->send('GET', $path, [] === $query ? [] : ['query' => $query], $this->installationToken($installationId));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<mixed>
     *
     * @throws GitHubAppApiFailed
     */
    public function post(int $installationId, string $path, array $body): array
    {
        return $this->send('POST', $path, ['json' => $body], $this->installationToken($installationId));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<mixed> the decoded answer, or an empty array when GitHub accepts with no body
     *
     * @throws GitHubAppApiFailed
     */
    public function put(int $installationId, string $path, array $body): array
    {
        return $this->send('PUT', $path, ['json' => $body], $this->installationToken($installationId), emptyBodyAccepted: true);
    }

    /** @throws GitHubAppApiFailed */
    private function jwt(): string
    {
        $appId = $this->configuration->appId;
        $key = str_replace('\n', "\n", trim((string) $this->configuration->appPrivateKey));
        if (null === $appId || '' === $appId || '' === $key) {
            throw new GitHubAppApiFailed('not_configured');
        }

        // GitHub refuses an iat ahead of its own clock, and an exp more than ten minutes out.
        $now = $this->clock->now();
        try {
            return Builder::new(new JoseEncoder(), ChainedFormatter::withUnixTimestampDates())
                ->issuedBy($appId)
                ->issuedAt($now->modify('-60 seconds'))
                ->expiresAt($now->modify('+9 minutes'))
                ->getToken(new Sha256(), InMemory::plainText($key))
                ->toString();
        } catch (JwtException) {
            throw new GitHubAppApiFailed('bad_key');
        }
    }

    /**
     * GitHub answers a rate limit with 403 or 429.
     *
     * @param array<string, list<string>> $headers
     */
    private static function rateLimited(int $status, array $headers): bool
    {
        return 429 === $status
            || (403 === $status && ('0' === ($headers['x-ratelimit-remaining'][0] ?? null) || isset($headers['retry-after'])));
    }

    /**
     * The seconds of `retry-after`, else the wait until the `x-ratelimit-reset` epoch.
     *
     * @param array<string, list<string>> $headers
     */
    private function retryAfter(array $headers): ?int
    {
        $retryAfter = $headers['retry-after'][0] ?? null;
        if (null !== $retryAfter && ctype_digit($retryAfter)) {
            return (int) $retryAfter;
        }

        $reset = $headers['x-ratelimit-reset'][0] ?? null;
        if (null !== $reset && ctype_digit($reset)) {
            return max(0, (int) $reset - $this->clock->now()->getTimestamp());
        }

        return null;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     *
     * @throws GitHubAppApiFailed
     */
    private function send(string $method, string $path, array $options, ?string $installationToken = null, bool $emptyBodyAccepted = false): array
    {
        $options['headers'] = ['Authorization' => 'Bearer '.($installationToken ?? $this->jwt())];
        try {
            $response = $this->githubApiClient->request($method, $path, $options);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $headers = $response->getHeaders(false);
                $rateLimited = self::rateLimited($status, $headers);
                throw new GitHubAppApiFailed('http_status', $status, $rateLimited, $rateLimited ? $this->retryAfter($headers) : null);
            }

            if ($emptyBodyAccepted && '' === $response->getContent()) {
                return [];
            }

            return $response->toArray();
        } catch (TransportExceptionInterface) {
            throw new GitHubAppApiFailed('transport');
        } catch (DecodingExceptionInterface) {
            throw new GitHubAppApiFailed('malformed_body');
        }
    }
}
