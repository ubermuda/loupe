<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Mercure\UserTopicBuilder;
use App\Module\Bridge\Service\HeartbeatInterval;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final readonly class ShowEventsHandler
{
    /**
     * Subscriber JWTs are deliberately short-lived: a leaked credential stops
     * working within the hour, and clients simply re-request on a 401.
     */
    private const int JWT_TTL_SECONDS = 3600;

    private const string INBOX_FLAG = 'inbox.enabled';

    public function __construct(
        private ProjectRepository $projects,
        private UserTopicBuilder $userTopics,
        private FeatureFlagService $featureFlags,
        private HeartbeatInterval $heartbeatInterval,

        /**
         * A closure, because the factory reads MERCURE_JWT_SECRET, which an
         * instance with no hub does not set. Injected directly, the container
         * cannot build this controller at all, and the request dies in
         * ControllerResolver before RequireFeatureFlag can answer 404.
         *
         * @var \Closure(): TokenFactoryInterface
         */
        #[AutowireServiceClosure('mercure.hub.default.jwt.factory')]
        private \Closure $tokenFactory,

        #[Autowire(env: 'MERCURE_PUBLIC_URL')]
        private string $hubUrl,
    ) {
    }

    public function __invoke(ShowEventsCommand $command): ShowEventsView
    {
        // The JWT names the caller's own topic only, so no other user's events reach it.
        $topic = $this->userTopics->forUser($command->user->id ?? throw new \LogicException('User has no id.'));

        return new ShowEventsView(
            hubUrl: $this->hubUrl,
            jwt: ($this->tokenFactory)()->create(
                [$topic],
                [],
                ['exp' => new \DateTimeImmutable('+'.self::JWT_TTL_SECONDS.' seconds')],
            ),
            topic: $topic,
            projects: $this->projects->findByOwner($command->user),
            flags: $this->sharedFlags(),
        );
    }

    /**
     * The only flags an agent token reads. Any other flag stays on the server.
     *
     * @return array<string, bool|int>
     */
    private function sharedFlags(): array
    {
        return [
            self::INBOX_FLAG => $this->featureFlags->isEnabled(self::INBOX_FLAG),
            HeartbeatInterval::FLAG => $this->heartbeatInterval->seconds(),
        ];
    }
}
