<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Project\Entity\Project;
use App\Outbox\Entity\OutboxEvent;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists one outbox row on the project's topic. It never flushes: a producer
 * calls it inside the transaction of the change it reports, and that
 * transaction's flush writes the row.
 */
final readonly class OutboxWriter
{
    public function __construct(
        private ProjectTopicBuilder $topics,
        private EntityManagerInterface $em,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function write(Project $project, string $type, array $payload): void
    {
        $this->em->persist(new OutboxEvent(
            project: $project,
            type: $type,
            topic: $this->topics->forProject($project->id ?? throw new \LogicException('Project has no id.')),
            payload: json_encode($payload, \JSON_THROW_ON_ERROR),
        ));
    }
}
