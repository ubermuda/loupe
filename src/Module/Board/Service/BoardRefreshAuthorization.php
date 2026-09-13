<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Project\Entity\Project;
use App\Outbox\AgentPush;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\Authorization;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Lets the browser that renders a board listen for its column changes. The
 * caller must already have checked that the user may view the project: this
 * grants a subscriber token for that project's board topic, and no other.
 */
final readonly class BoardRefreshAuthorization
{
    /**
     * @param \Closure(): Authorization $authorization a closure, because
     *                                                 building it reads
     *                                                 MERCURE_JWT_SECRET
     */
    public function __construct(
        private ProjectTopicBuilder $topics,
        private FeatureFlagService $featureFlags,
        private RequestStack $requests,
        private LoggerInterface $logger,

        #[AutowireServiceClosure(Authorization::class)]
        private \Closure $authorization,

        #[Autowire(env: 'MERCURE_PUBLIC_URL')]
        private string $hubUrl,
    ) {
    }

    /**
     * Sets the token cookie on the response and returns the URL to subscribe
     * to, or null when this instance has no hub to listen to.
     */
    public function authorize(Project $project): ?string
    {
        // A refused column form forwards to the board, and the bundle only
        // writes a cookie that sits on the main request.
        $request = $this->requests->getMainRequest();
        if (null === $request || !$this->featureFlags->isEnabled(AgentPush::FLAG)) {
            return null;
        }

        $topic = $this->topics->forBoard($project->id ?? throw new \LogicException('Project has no id.'));

        try {
            ($this->authorization)()->setCookie($request, [$topic]);
        } catch (\Throwable $e) {
            // A hub on another site cannot read a cookie this host sets.
            $this->logger->warning('board.refresh_authorization_failed', [
                'projectId' => (string) $project->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->hubUrl.'?'.http_build_query(['topic' => $topic]);
    }
}
