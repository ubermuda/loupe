<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Board\Repository\CardRepository;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Review\Repository\DocumentRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * A test fixture for browser tests and previews: one open ask holding a
 * question and a to-do. Agents ask through MCP, which a browser cannot call.
 */
#[When('dev')]
final readonly class SeedInboxFixtureHandler
{
    public function __construct(
        private InboxItemRepository $inboxItems,
        private CardRepository $cards,
        private DocumentRepository $documents,
        private EntityManagerInterface $em,
    ) {
    }

    /** @return array{ask: InboxAsk, question: InboxItem, todo: InboxItem} */
    public function __invoke(SeedInboxFixtureCommand $command): array
    {
        return $this->em->wrapInTransaction(function () use ($command): array {
            $project = $command->project;
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);
            $number = $this->inboxItems->nextNumber($project);

            $ask = new InboxAsk(
                project: $project,
                sessionId: Uuid::v4(),
                bridgeId: Uuid::v4(),
                context: 'Working on the **export** card. I need one decision before I write the migration.',
            );
            $question = new InboxItem(
                project: $project,
                number: $number,
                kind: InboxItemKind::Question,
                title: 'Which format should the export use?',
                blocking: true,
                body: 'Both formats work with the importer.',
                options: ['JSON', 'CSV'],
                freeText: true,
            );
            $todo = new InboxItem(
                project: $project,
                number: $number + 1,
                kind: InboxItemKind::Todo,
                title: 'Review pull request 482',
                blocking: false,
            );
            $ask->items->add(new InboxAskItem($ask, $question));
            $ask->items->add(new InboxAskItem($ask, $todo));

            if (null !== $command->cardId) {
                $card = $this->cards->findOneByIdAndProjectId($command->cardId, (string) $project->id)
                    ?? throw new NotFoundHttpException('The project has no card with this id.');
                $question->cards->add(new InboxItemCard($question, $card));
            }
            if (null !== $command->documentId) {
                $document = $this->documents->findOneByIdAndProjectId($command->documentId, (string) $project->id)
                    ?? throw new NotFoundHttpException('The project has no document with this id.');
                $question->documents->add(new InboxItemDocument($question, $document));
            }

            $this->em->persist($ask);
            $this->em->persist($question);
            $this->em->persist($todo);
            $this->em->flush();

            return ['ask' => $ask, 'question' => $question, 'todo' => $todo];
        });
    }
}
