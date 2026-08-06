<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Project\Repository\ProjectRepository;
use App\Module\SiteReview\Service\SiteReviewTopicBuilder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;

final readonly class ShowStreamCredentialsHandler
{
    /**
     * Subscriber JWTs are deliberately short-lived: a leaked credential stops
     * working within the hour, and clients simply re-request on a 401.
     */
    private const int JWT_TTL_SECONDS = 3600;

    public function __construct(
        private ProjectRepository $projects,
        private SiteReviewTopicBuilder $topicBuilder,

        #[Autowire(service: 'mercure.hub.default.jwt.factory')]
        private TokenFactoryInterface $tokenFactory,

        #[Autowire(env: 'MERCURE_PUBLIC_URL')]
        private string $hubUrl,
    ) {
    }

    public function __invoke(ShowStreamCredentialsCommand $command): ShowStreamCredentialsView
    {
        // Owner-scoped lookup is what enforces project ownership: the caller can
        // only ever obtain credentials for its own projects.
        $project = $this->projects->findOneByIdOrNameForOwner($command->handle, $command->owner);
        if (null === $project) {
            return new ShowStreamCredentialsView(null, $this->hubUrl, '', '');
        }

        $topic = $this->topicBuilder->forProject(
            $project->id ?? throw new \LogicException('Project has no id.'),
        );

        return new ShowStreamCredentialsView(
            site: $project,
            hubUrl: $this->hubUrl,
            topic: $topic,
            jwt: $this->tokenFactory->create(
                [$topic],
                [],
                ['exp' => new \DateTimeImmutable('+'.self::JWT_TTL_SECONDS.' seconds')],
            ),
        );
    }
}
