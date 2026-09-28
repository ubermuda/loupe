<?php

declare(strict_types=1);

namespace App\Module\Board\Twig;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Project\Entity\Project;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** A page outside the board that follows card changes passes this topic to mercure_subscribe(). */
final class BoardTopicExtension extends AbstractExtension
{
    public function __construct(
        private readonly ProjectTopicBuilder $topics,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('board_topic', $this->boardTopic(...)),
        ];
    }

    public function boardTopic(Project $project): string
    {
        return $this->topics->forBoard($project->id ?? throw new \LogicException('Project has no id.'));
    }
}
