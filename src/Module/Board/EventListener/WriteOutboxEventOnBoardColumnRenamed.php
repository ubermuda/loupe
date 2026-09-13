<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\BoardEventType;
use App\Module\Board\Event\BoardColumnRenamed;
use App\Outbox\OutboxWriter;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Tells the reader of the outbox that a slug it may match on is gone. A rename
 * that keeps the slug changes nothing the reader sees, so it writes no row.
 *
 * It runs inside RenameBoardColumnHandler's transaction, so it persists and
 * lets that transaction flush. It must never throw: anything raised here aborts
 * the rename.
 */
#[AsEventListener]
final readonly class WriteOutboxEventOnBoardColumnRenamed
{
    public function __construct(
        private OutboxWriter $outbox,
    ) {
    }

    public function __invoke(BoardColumnRenamed $event): void
    {
        if ($event->fromSlug === $event->toSlug) {
            return;
        }

        $column = $event->column;
        $project = $column->project;

        // A contract with the reader of the outbox, like the card move payload.
        // No label: text a person wrote must never reach an agent.
        $this->outbox->write($project, BoardEventType::COLUMN_RENAMED, [
            'type' => BoardEventType::COLUMN_RENAMED,
            'projectId' => (string) $project->id,
            'subject' => ['type' => 'board_column', 'id' => (string) $column->id],
            'actor' => $event->actor->value,
            'fromSlug' => $event->fromSlug,
            'toSlug' => $event->toSlug,
        ]);
    }
}
