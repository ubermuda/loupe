<?php

declare(strict_types=1);

namespace App\Module\Project\EventListener;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Project\Event\ProjectRenamed;
use App\Module\Project\ProjectEventType;
use App\Outbox\Entity\OutboxEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Turns a slug change into a durable outbox row, so a bridge whose rules name
 * the old slug can mark them dead. It persists into the flush that saves the
 * project and must never throw, because anything raised here aborts that save.
 */
#[AsEventListener]
final readonly class WriteOutboxEventOnProjectRenamed
{
    public function __construct(
        private ProjectTopicBuilder $topics,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ProjectRenamed $event): void
    {
        $project = $event->project;

        // Every key and value is a contract with the reader of the outbox. The
        // slugs are derived, and the name stays out: text a person wrote must
        // never reach an agent through a directive.
        $payload = [
            'type' => ProjectEventType::RENAMED,
            'subject' => ['type' => 'project', 'id' => (string) $project->id],
            'projectId' => (string) $project->id,
            'fromSlug' => $event->fromSlug,
            'toSlug' => $event->toSlug,
            'actor' => $event->actor,
        ];

        $this->em->persist(new OutboxEvent(
            project: $project,
            type: ProjectEventType::RENAMED,
            topic: $this->topics->forProject($project->id ?? throw new \LogicException('Project has no id.')),
            payload: json_encode($payload, \JSON_THROW_ON_ERROR),
        ));
    }
}
