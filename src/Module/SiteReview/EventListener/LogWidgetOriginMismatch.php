<?php

declare(strict_types=1);

namespace App\Module\SiteReview\EventListener;

use App\Module\Project\Security\AuthenticatedProjectResolver;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Records widget traffic arriving from somewhere other than the project's
 * registered domain. Observation only — nothing is refused, because Origin is
 * forgeable by any non-browser caller and would buy no guarantee.
 */
#[AsEventListener]
final readonly class LogWidgetOriginMismatch
{
    private const int REPEAT_AFTER_SECONDS = 3600;

    public function __construct(
        private AuthenticatedProjectResolver $projects,
        private CacheItemPoolInterface $seen,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/site-review')) {
            return;
        }

        // Absent on same-origin GETs and on the Go bridge CLI, so it is not a
        // signal — only a present-and-different Origin says anything.
        $origin = $request->headers->get('Origin');
        if (null === $origin) {
            return;
        }

        $project = $this->projects->resolveWidgetProject();
        if (null === $project || null === $project->domain) {
            return;
        }

        $host = parse_url($origin, \PHP_URL_HOST);
        if (!is_string($host) || self::normalise($host) === self::normalise($project->domain)) {
            return;
        }

        // One warning per project per hour. Safe methods bypass
        // RateLimitSiteReviewWrites, so keying on the origin too would let a
        // token holder mint a warning per request by varying it.
        $item = $this->seen->getItem('site_review.origin_mismatch.'.(string) $project->id);
        if ($item->isHit()) {
            return;
        }

        $this->seen->save($item->set(true)->expiresAfter(self::REPEAT_AFTER_SECONDS));

        $this->logger->warning('site_review.widget_origin_mismatch', [
            'project' => (string) $project->id,
            'origin' => $origin,
            'domain' => $project->domain,
        ]);
    }

    /** `domain` is free text, so both sides are reduced to a bare lowercase host. */
    private static function normalise(string $value): string
    {
        $host = parse_url(str_contains($value, '//') ? $value : '//'.$value, \PHP_URL_HOST);

        return preg_replace('/^www\./', '', strtolower((string) $host)) ?? '';
    }
}
