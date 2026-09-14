<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Mercure\UserTopicBuilder;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final readonly class ShowEventsHandler
{
    /**
     * Subscriber JWTs are deliberately short-lived: a leaked credential stops
     * working within the hour, and clients simply re-request on a 401.
     */
    private const int JWT_TTL_SECONDS = 3600;

    /** The only flags an agent token reads. Any other flag stays on the server. */
    private const array SHARED_FLAGS = [
        'inbox.enabled',
    ];

    public function __construct(
        private ProjectRepository $projects,
        private UserTopicBuilder $userTopics,
        private FeatureFlagService $featureFlags,

        #[Autowire(service: 'mercure.hub.default.jwt.factory')]
        private TokenFactoryInterface $tokenFactory,

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
            jwt: $this->tokenFactory->create(
                [$topic],
                [],
                ['exp' => new \DateTimeImmutable('+'.self::JWT_TTL_SECONDS.' seconds')],
            ),
            topic: $topic,
            projects: $this->projects->findByOwner($command->user),
            flags: $this->sharedFlags(),
        );
    }

    /** @return array<string, bool> */
    private function sharedFlags(): array
    {
        $flags = [];
        foreach (self::SHARED_FLAGS as $name) {
            $flags[$name] = $this->featureFlags->isEnabled($name);
        }

        return $flags;
    }
}
