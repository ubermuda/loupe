<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Compares this build against the latest published release. Off unless an
 * operator turns the flag on, because it is the one outbound call a page view
 * makes and it tells the host that this instance exists.
 */
final readonly class UpdateCheck
{
    public const string FLAG = 'about.update_check.enabled';

    private const int SUCCESS_TTL = 21600;

    /** Short, so one refused request does not hide an update for six hours. */
    private const int FAILURE_TTL = 300;

    public function __construct(
        private BuildIdentity $build,
        private FeatureFlagService $featureFlags,
        private HttpClientInterface $githubApiClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,

        #[Autowire(param: 'app.source_url')]
        private string $sourceUrl,
    ) {
    }

    public function status(): ?UpdateStatus
    {
        $current = self::releaseNumber($this->build->version);
        $repository = $this->repository();

        // A build between releases has nothing a release can be compared
        // against, and source this cannot query has nothing to answer.
        if (null === $current || null === $repository || !$this->featureFlags->isEnabled(self::FLAG)) {
            return null;
        }

        $latest = $this->latestRelease($repository);
        $latestNumber = self::releaseNumber($latest);

        if (null === $latest || null === $latestNumber) {
            return null;
        }

        // Ordered, not merely different: a build ahead of the latest release —
        // or a rollback that leaves the API reporting an older tag — is not out
        // of date, and saying it is would send someone downgrading.
        return new UpdateStatus($latest, version_compare($current, $latestNumber, '<'));
    }

    /**
     * The comparable number in a release tag, or null for anything that is not
     * one. `git describe` appends -<commits>-g<sha> once a build moves past its
     * tag and -dirty when the tree was not clean, and a tag like `nightly` has
     * no ordering at all — none of those can be ranked against a release.
     */
    private static function releaseNumber(?string $tag): ?string
    {
        if (null === $tag || 1 !== preg_match('/^v?(\d+(?:\.\d+)*)$/', $tag, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function latestRelease(string $repository): ?string
    {
        $key = 'app.update_check.'.str_replace('/', '.', $repository);

        return $this->cache->get($key, function (ItemInterface $item) use ($repository): ?string {
            $item->expiresAfter(self::FAILURE_TTL);

            try {
                $response = $this->githubApiClient->request('GET', '/repos/'.$repository.'/releases/latest');
                $status = $response->getStatusCode();

                // Checked before reading the body: toArray() throws on a
                // non-2xx, which would abort rather than degrade.
                if ($status < 200 || $status >= 300) {
                    $this->logger->info('update_check.unavailable', ['repository' => $repository, 'status' => $status]);

                    return null;
                }

                $tag = $response->toArray()['tag_name'] ?? null;
            } catch (TransportExceptionInterface|DecodingExceptionInterface $e) {
                $this->logger->info('update_check.failed', ['repository' => $repository, 'error' => $e->getMessage()]);

                return null;
            }

            if (!is_string($tag) || '' === $tag) {
                return null;
            }

            $item->expiresAfter(self::SUCCESS_TTL);

            return $tag;
        });
    }

    /** @return string|null owner/name, or null when the source is not on GitHub */
    private function repository(): ?string
    {
        $path = parse_url($this->sourceUrl, PHP_URL_PATH);

        if ('github.com' !== parse_url($this->sourceUrl, PHP_URL_HOST) || !is_string($path)) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => '' !== $segment));

        if (2 !== count($segments)) {
            return null;
        }

        $name = str_ends_with($segments[1], '.git') ? substr($segments[1], 0, -4) : $segments[1];

        return $segments[0].'/'.$name;
    }
}
