<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\GitHubRepositoryRef;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The calls Loupe makes to GitHub as a user. This class never stores a token
 * and never puts one in an exception.
 */
final readonly class GitHubUserApi
{
    private const int PAGE_SIZE = 100;
    private const int MAX_PAGES = 10;

    public function __construct(
        private HttpClientInterface $githubOauthClient,
        private HttpClientInterface $githubApiClient,
        private GitHubAppConfiguration $configuration,
        private ClockInterface $clock,
    ) {
    }

    /** @throws GitHubUserApiFailed */
    public function exchangeCode(string $code, string $codeVerifier, string $redirectUri): GitHubUserTokens
    {
        return $this->tokens([
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ]);
    }

    /**
     * A refresh token works once, so the caller must store the new set at once.
     *
     * @throws GitHubUserApiFailed with reason `token_bad_refresh_token` when GitHub refuses the token for good
     */
    public function refresh(#[\SensitiveParameter] string $refreshToken): GitHubUserTokens
    {
        return $this->tokens(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    /** @throws GitHubUserApiFailed */
    public function user(#[\SensitiveParameter] string $token): GitHubUserProfile
    {
        $body = $this->send($this->githubApiClient, 'GET', '/user', ['headers' => ['Authorization' => 'Bearer '.$token]]);
        $id = $body['id'] ?? null;
        $login = $body['login'] ?? null;
        if (!\is_int($id) || !\is_string($login) || '' === $login) {
            throw new GitHubUserApiFailed('malformed_body');
        }

        return new GitHubUserProfile($id, $login);
    }

    /**
     * Removes the App's grant for the user, which voids every token of it.
     *
     * @throws GitHubUserApiFailed
     */
    public function revokeGrant(#[\SensitiveParameter] string $accessToken): void
    {
        $clientId = $this->configuration->clientId;
        $clientSecret = $this->configuration->clientSecret;
        if (null === $clientId || '' === $clientId || null === $clientSecret || '' === $clientSecret) {
            throw new GitHubUserApiFailed('not_configured');
        }

        try {
            $status = $this->githubApiClient->request('DELETE', '/applications/'.rawurlencode($clientId).'/grant', [
                'auth_basic' => [$clientId, $clientSecret],
                'json' => ['access_token' => $accessToken],
            ])->getStatusCode();
        } catch (TransportExceptionInterface) {
            throw new GitHubUserApiFailed('transport');
        }

        if (204 !== $status) {
            throw new GitHubUserApiFailed('http_status', $status);
        }
    }

    /**
     * Submits a review as the user. The body may be empty for an approval.
     *
     * @param 'APPROVE'|'REQUEST_CHANGES'|'COMMENT' $event
     *
     * @return ?string the URL of the review
     *
     * @throws GitHubUserApiFailed
     */
    public function postReview(#[\SensitiveParameter] string $token, string $repositoryPath, int $number, string $event, string $body): ?string
    {
        $payload = ['event' => $event];
        if ('' !== $body) {
            $payload['body'] = $body;
        }

        $answer = $this->send($this->githubApiClient, 'POST', $repositoryPath.'/pulls/'.$number.'/reviews', [
            'headers' => ['Authorization' => 'Bearer '.$token],
            'json' => $payload,
        ]);
        $url = $answer['html_url'] ?? null;

        return \is_string($url) && '' !== $url ? $url : null;
    }

    /**
     * @return list<GitHubUserInstallation>
     *
     * @throws GitHubUserApiFailed
     */
    public function installations(string $token): array
    {
        $installations = [];
        [$entries] = $this->pages($token, '/user/installations', 'installations');
        foreach ($entries as $entry) {
            $id = $entry['id'] ?? null;
            $login = \is_array($entry['account'] ?? null) ? ($entry['account']['login'] ?? null) : null;
            if (!\is_int($id) || !\is_string($login) || '' === $login) {
                continue;
            }

            $selection = GitHubRepositorySelection::tryFrom(\is_string($entry['repository_selection'] ?? null) ? $entry['repository_selection'] : '');
            $installations[] = new GitHubUserInstallation($id, $login, $selection ?? GitHubRepositorySelection::Selected);
        }

        return $installations;
    }

    /**
     * The repositories of the installation that both the user and the App reach.
     *
     * @throws GitHubUserApiFailed
     */
    public function installationRepositories(string $token, int $installationId): GitHubRepositoryList
    {
        $repositories = [];
        [$entries, $complete] = $this->pages($token, '/user/installations/'.$installationId.'/repositories', 'repositories');
        foreach ($entries as $entry) {
            $id = $entry['id'] ?? null;
            $fullName = $entry['full_name'] ?? null;
            if (\is_int($id) && \is_string($fullName) && '' !== $fullName) {
                $repositories[] = new GitHubRepositoryRef($id, $fullName);
            }
        }

        return new GitHubRepositoryList($repositories, $complete);
    }

    /**
     * @param array<string, string> $grant
     *
     * @throws GitHubUserApiFailed
     */
    private function tokens(array $grant): GitHubUserTokens
    {
        $body = $this->send($this->githubOauthClient, 'POST', '/login/oauth/access_token', ['body' => [
            'client_id' => $this->configuration->clientId,
            'client_secret' => $this->configuration->clientSecret,
            ...$grant,
        ]]);

        $accessToken = $body['access_token'] ?? null;
        if (!\is_string($accessToken) || '' === $accessToken) {
            // GitHub answers a refused exchange with 200 and an error code.
            $error = $body['error'] ?? null;

            throw new GitHubUserApiFailed(\is_string($error) && 1 === preg_match('/^[a-z_]{1,64}$/', $error) ? 'token_'.$error : 'token_missing');
        }

        $refreshToken = $body['refresh_token'] ?? null;
        $now = $this->clock->now();

        return new GitHubUserTokens(
            $accessToken,
            \is_string($refreshToken) && '' !== $refreshToken ? $refreshToken : null,
            $this->expiry($now, $body['expires_in'] ?? null),
            $this->expiry($now, $body['refresh_token_expires_in'] ?? null),
        );
    }

    private function expiry(\DateTimeImmutable $now, mixed $seconds): ?\DateTimeImmutable
    {
        return \is_int($seconds) && $seconds > 0 ? $now->modify('+'.$seconds.' seconds') : null;
    }

    /**
     * A full last page counts as incomplete, because GitHub may hold more.
     *
     * @return array{list<array<mixed>>, bool} the entries, and whether they are all of them
     *
     * @throws GitHubUserApiFailed
     */
    private function pages(string $token, string $path, string $key): array
    {
        $entries = [];
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $body = $this->send($this->githubApiClient, 'GET', $path, [
                'headers' => ['Authorization' => 'Bearer '.$token],
                'query' => ['per_page' => self::PAGE_SIZE, 'page' => $page],
            ]);
            $items = $body[$key] ?? null;
            if (!\is_array($items)) {
                throw new GitHubUserApiFailed('malformed_body');
            }

            foreach ($items as $item) {
                if (\is_array($item)) {
                    $entries[] = $item;
                }
            }

            if (\count($items) < self::PAGE_SIZE) {
                return [$entries, true];
            }
        }

        return [$entries, false];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     *
     * @throws GitHubUserApiFailed
     */
    private function send(HttpClientInterface $client, string $method, string $path, array $options): array
    {
        try {
            $response = $client->request($method, $path, $options);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new GitHubUserApiFailed('http_status', $status);
            }

            return $response->toArray();
        } catch (TransportExceptionInterface) {
            throw new GitHubUserApiFailed('transport');
        } catch (DecodingExceptionInterface) {
            throw new GitHubUserApiFailed('malformed_body');
        }
    }
}
