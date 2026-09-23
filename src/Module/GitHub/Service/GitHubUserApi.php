<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\GitHubRepositoryRef;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The calls the App install makes as the installing user. The user token lives
 * in the caller's request only: this class never stores it or puts it in an
 * exception.
 */
final readonly class GitHubUserApi
{
    private const int PAGE_SIZE = 100;
    private const int MAX_PAGES = 10;

    public function __construct(
        private HttpClientInterface $githubOauthClient,
        private HttpClientInterface $githubApiClient,
        private GitHubAppConfiguration $configuration,
    ) {
    }

    /**
     * @return non-empty-string the user access token
     *
     * @throws GitHubUserApiFailed
     */
    public function exchangeCode(string $code, string $codeVerifier, string $redirectUri): string
    {
        $body = $this->send($this->githubOauthClient, 'POST', '/login/oauth/access_token', ['body' => [
            'client_id' => $this->configuration->clientId,
            'client_secret' => $this->configuration->clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ]]);

        $token = $body['access_token'] ?? null;
        if (\is_string($token) && '' !== $token) {
            return $token;
        }

        // GitHub answers a refused exchange with 200 and an error code.
        $error = $body['error'] ?? null;

        throw new GitHubUserApiFailed(\is_string($error) && 1 === preg_match('/^[a-z_]{1,64}$/', $error) ? 'token_'.$error : 'token_missing');
    }

    /**
     * @return list<GitHubUserInstallation>
     *
     * @throws GitHubUserApiFailed
     */
    public function installations(string $token): array
    {
        $installations = [];
        foreach ($this->pages($token, '/user/installations', 'installations') as $entry) {
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
     * @return list<GitHubRepositoryRef>
     *
     * @throws GitHubUserApiFailed
     */
    public function installationRepositories(string $token, int $installationId): array
    {
        $repositories = [];
        foreach ($this->pages($token, '/user/installations/'.$installationId.'/repositories', 'repositories') as $entry) {
            $id = $entry['id'] ?? null;
            $fullName = $entry['full_name'] ?? null;
            if (\is_int($id) && \is_string($fullName) && '' !== $fullName) {
                $repositories[] = new GitHubRepositoryRef($id, $fullName);
            }
        }

        return $repositories;
    }

    /**
     * @return list<array<mixed>>
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
                break;
            }
        }

        return $entries;
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
