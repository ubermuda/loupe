<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Inbox\Command\AnswerInboxItemCommand;
use App\Module\Inbox\Command\AnswerInboxItemHandler;
use App\Module\Inbox\Command\DeclineInboxItemCommand;
use App\Module\Inbox\Command\DeclineInboxItemHandler;
use App\Module\Inbox\Command\MarkInboxItemDoneCommand;
use App\Module\Inbox\Command\MarkInboxItemDoneHandler;
use App\Module\Inbox\Command\WithdrawInboxItemCommand;
use App\Module\Inbox\Command\WithdrawInboxItemHandler;
use App\Module\Inbox\Entity\InboxCardWaitEndReason;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Messenger\ReconcileCardWaits;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\Service\CardWaitReconciler;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** A wait item takes one response, Dismiss, and every other response path refuses it. */
final class WaitItemResponsesTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private CardWaitReconciler $reconciler;
    private Project $project;
    private Card $card;
    private Document $document;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $reconciler = self::getContainer()->get(CardWaitReconciler::class);
        self::assertInstanceOf(CardWaitReconciler::class, $reconciler);
        $this->reconciler = $reconciler;

        $this->project = $this->project($em, $this->owner($em, 'wait-responses'), 'wait-responses');
        $this->card = $this->card($em, $this->project, 7);
        $this->document = new Document($this->project->owner, $this->project, 'Tech design');
        $this->document->addVersion('# One', '<h1>One</h1>');
        $em->persist($this->document);
        $this->card->documents->add(new CardDocument($this->card, $this->document));
        $this->stageDocument($em, $this->document, $this->card);
        $em->flush();
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
    }

    public function test_a_dismissal_declines_the_item_and_stamps_the_watch_and_its_waits(): void
    {
        $watch = $this->openWatch();
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        $this->decline($watch->item);

        $this->em->clear();
        $stored = $this->watches()[0];
        self::assertSame(InboxItemState::Declined, $stored->item->state);
        self::assertNull($stored->item->closeNote);
        self::assertNotNull($stored->dismissedAt);
        self::assertNotNull($stored->closedAt);
        self::assertCount(1, $stored->waits);
        self::assertSame(InboxCardWaitEndReason::Dismissed, $stored->waits[0]?->endReason);
        self::assertNotNull($stored->waits[0]->endedAt);

        $sent = array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()),
            static fn (object $message): bool => $message instanceof ReconcileCardWaits,
        ));
        self::assertEquals([new ReconcileCardWaits((string) $this->project->id, [(string) $this->card->id])], array_values(array_unique($sent, SORT_REGULAR)));
    }

    public function test_a_reconcile_after_a_dismissal_does_not_reopen_the_same_version(): void
    {
        $this->decline($this->openWatch()->item);

        $this->reconcile();

        $watches = $this->watches();
        self::assertCount(1, $watches);
        self::assertSame(InboxItemState::Declined, $watches[0]->item->state);
    }

    public function test_answer_refuses_a_wait_item(): void
    {
        $item = $this->openWatch()->item;
        $handler = self::getContainer()->get(AnswerInboxItemHandler::class);
        self::assertInstanceOf(AnswerInboxItemHandler::class, $handler);

        self::assertSame(['selectedOptions' => 'inbox.answer.error.not_a_question'], $this->refusal(static fn () => $handler(new AnswerInboxItemCommand($item, '', 'Yes'))));
        self::assertSame(InboxItemState::Open, $this->watches()[0]->item->state);
    }

    public function test_mark_done_refuses_a_wait_item(): void
    {
        $item = $this->openWatch()->item;
        $handler = self::getContainer()->get(MarkInboxItemDoneHandler::class);
        self::assertInstanceOf(MarkInboxItemDoneHandler::class, $handler);

        self::assertSame(['item' => 'inbox.done.error.not_a_todo'], $this->refusal(static fn () => $handler(new MarkInboxItemDoneCommand($item))));
        self::assertSame(InboxItemState::Open, $this->watches()[0]->item->state);
    }

    public function test_withdraw_refuses_a_wait_item(): void
    {
        $item = $this->openWatch()->item;
        $handler = self::getContainer()->get(WithdrawInboxItemHandler::class);
        self::assertInstanceOf(WithdrawInboxItemHandler::class, $handler);

        self::assertSame(['itemId' => WithdrawInboxItemHandler::WAIT_NOT_WITHDRAWABLE], $this->refusal(static fn () => $handler(new WithdrawInboxItemCommand($item, 'Not needed'))));
        self::assertSame('inbox.item.error.wait_not_withdrawable', WithdrawInboxItemHandler::WAIT_NOT_WITHDRAWABLE);
        $watch = $this->watches()[0];
        self::assertSame(InboxItemState::Open, $watch->item->state);
        self::assertNull($watch->closedAt);
    }

    private function openWatch(): InboxCardWatch
    {
        $this->reconcile();
        $watches = $this->watches();
        self::assertCount(1, $watches);
        self::assertSame(InboxItemState::Open, $watches[0]->item->state);

        return $watches[0];
    }

    private function reconcile(): void
    {
        $project = $this->em->find(Project::class, $this->project->id);
        self::assertInstanceOf(Project::class, $project);
        $this->reconciler->reconcile($project, [(string) $this->card->id]);
    }

    private function decline(InboxItem $item): void
    {
        $handler = self::getContainer()->get(DeclineInboxItemHandler::class);
        self::assertInstanceOf(DeclineInboxItemHandler::class, $handler);
        $handler(new DeclineInboxItemCommand($item, ''));
    }

    /** @return list<InboxCardWatch> */
    private function watches(): array
    {
        $this->em->clear();
        $repository = self::getContainer()->get(InboxCardWatchRepository::class);
        self::assertInstanceOf(InboxCardWatchRepository::class, $repository);

        return array_values($repository->findBy(['cardId' => $this->card->id]));
    }

    /**
     * @param \Closure(): mixed $call
     *
     * @return array<string, string>
     */
    private function refusal(\Closure $call): array
    {
        try {
            $call();
        } catch (DomainErrors $e) {
            return $e->errors;
        }
        self::fail('The handler accepted the response.');
    }
}
