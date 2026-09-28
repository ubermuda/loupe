<?php

declare(strict_types=1);

namespace App\Outbox\Twig;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Project\Entity\Project;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** A page that lists activity events passes this topic to mercure_subscribe(). */
final class ActivityTopicExtension extends AbstractExtension
{
    public function __construct(
        private readonly ProjectTopicBuilder $topics,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('activity_topic', $this->activityTopic(...)),
        ];
    }

    public function activityTopic(Project $project): string
    {
        return $this->topics->forActivity($project->id ?? throw new \LogicException('Project has no id.'));
    }
}
