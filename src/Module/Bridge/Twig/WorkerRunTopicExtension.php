<?php

declare(strict_types=1);

namespace App\Module\Bridge\Twig;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Project\Entity\Project;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** A page that lists worker runs passes this topic to mercure_subscribe(). */
final class WorkerRunTopicExtension extends AbstractExtension
{
    public function __construct(
        private readonly ProjectTopicBuilder $topics,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('worker_runs_topic', $this->workerRunsTopic(...)),
        ];
    }

    public function workerRunsTopic(Project $project): string
    {
        return $this->topics->forWorkerRuns($project->id ?? throw new \LogicException('Project has no id.'));
    }
}
