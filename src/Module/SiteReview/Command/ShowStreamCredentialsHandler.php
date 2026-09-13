<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
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
        private ProjectTopicBuilder $topicBuilder,

        #[Autowire(service: 'mercure.hub.default.jwt.factory')]
        private TokenFactoryInterface $tokenFactory,

        #[Autowire(env: 'MERCURE_PUBLIC_URL')]
        private string $hubUrl,
    ) {
    }

    public function __invoke(ShowStreamCredentialsCommand $command): ShowStreamCredentialsView
    {
        // The owner-scoped query is what keeps another user's topics out of the JWT.
        $projects = array_map(
            fn (Project $project): StreamProjectView => new StreamProjectView(
                $project,
                $this->topicBuilder->forProject($project->id ?? throw new \LogicException('Project has no id.')),
            ),
            $this->projects->findByOwner($command->owner),
        );

        return new ShowStreamCredentialsView(
            hubUrl: $this->hubUrl,
            jwt: $this->tokenFactory->create(
                array_map(static fn (StreamProjectView $project): string => $project->topic, $projects),
                [],
                ['exp' => new \DateTimeImmutable('+'.self::JWT_TTL_SECONDS.' seconds')],
            ),
            projects: $projects,
        );
    }
}
