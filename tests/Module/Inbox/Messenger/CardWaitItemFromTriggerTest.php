<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Messenger;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Bridge\Command\ReportWorkerRunStateCommand;
use App\Module\Bridge\Command\ReportWorkerRunStateHandler;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/** Real handlers dispatch the events, and the queued messages run as the worker runs them. */
final class CardWaitItemFromTriggerTest extends KernelTestCase
{
    use InboxFixtures;

    public function test_a_card_linked_to_a_document_in_review_gets_a_wait_item_until_the_verdict(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $project = $this->project($em, $this->owner($em, 'wait-trigger-flow'), 'wait-trigger-flow');
        $document = $this->document($em, $project);
        $document->addVersion('# One', '<h1>One</h1>');
        $this->tagDocument($em, $document, ['tech-design', 'decisions']);
        $column = $this->stageColumn($em, $project, 'tech-design');
        $em->flush();
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);

        $createCard = $container->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $createCard);
        $card = $createCard(new CreateCardCommand($project, 'Ship it', 'Body', 'feature', column: $column, documentIds: [(string) $document->id]));
        $this->drainAsync();

        $watches = $container->get(InboxCardWatchRepository::class);
        self::assertInstanceOf(InboxCardWatchRepository::class, $watches);
        $open = $watches->findOpenForCards($project, [$card->id ?? throw new \LogicException('Card has no id.')]);
        self::assertCount(1, $open);
        $item = $open[0]->item;
        self::assertSame(InboxItemState::Open, $item->state);

        $submitReview = $container->get(SubmitReviewHandler::class);
        self::assertInstanceOf(SubmitReviewHandler::class, $submitReview);
        $submitReview(new SubmitReviewCommand($project->owner, $document, 'approved', 1));
        $this->drainAsync();

        self::assertSame([], $watches->findOpenForCards($project, [$card->id]));
        self::assertSame(InboxItemState::Done->value, $em->getConnection()->fetchOne('SELECT state FROM inbox_items WHERE id = :id', ['id' => (string) $item->id]));
    }

    public function test_a_blocked_run_gets_a_wait_item_until_a_newer_run_starts(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $project = $this->project($em, $this->owner($em, 'wait-run-flow'), 'wait-run-flow');
        $card = $this->card($em, $project, 7);
        $em->flush();
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
        $cardId = $card->id ?? throw new \LogicException('Card has no id.');

        $blocked = Uuid::v4();
        $this->reportRun($project, $cardId, $blocked, WorkerRunState::Queued);
        $this->reportRun($project, $cardId, $blocked, WorkerRunState::Blocked, "Needs the API key\nMore detail");
        $this->drainAsync();

        $watches = $container->get(InboxCardWatchRepository::class);
        self::assertInstanceOf(InboxCardWatchRepository::class, $watches);
        $open = $watches->findOpenForCards($project, [$cardId]);
        self::assertCount(1, $open);
        $item = $open[0]->item;
        self::assertSame(InboxItemState::Open, $item->state);
        self::assertSame('worker-run blocked', $item->body);

        $this->reportRun($project, $cardId, Uuid::v4(), WorkerRunState::Queued);
        $this->drainAsync();

        self::assertSame([], $watches->findOpenForCards($project, [$cardId]));
        self::assertSame(InboxItemState::Done->value, $em->getConnection()->fetchOne('SELECT state FROM inbox_items WHERE id = :id', ['id' => (string) $item->id]));
    }

    private function reportRun(Project $project, Uuid $cardId, Uuid $runKey, WorkerRunState $state, ?string $output = null): void
    {
        $handler = self::getContainer()->get(ReportWorkerRunStateHandler::class);
        self::assertInstanceOf(ReportWorkerRunStateHandler::class, $handler);
        $outcome = $state->isOutcome();
        $result = $handler(new ReportWorkerRunStateCommand(
            owner: $project->owner,
            handle: (string) $project->id,
            runKey: $runKey,
            bridgeId: Uuid::fromString('0199a0e2-9d4c-7c5e-9f2a-3b1c6d7e8f90'),
            state: $state,
            at: new \DateTimeImmutable(),
            subject: WorkSubject::card($cardId),
            cardNumber: 7,
            workKind: 'implement',
            sessionId: $outcome ? Uuid::v4() : null,
            startedAt: $outcome ? new \DateTimeImmutable('-1 minute') : null,
            endedAt: $outcome ? new \DateTimeImmutable() : null,
            exitCode: $outcome ? 0 : null,
            hasResult: $outcome ? true : null,
            output: $output,
            resultStatus: WorkerRunState::Blocked === $state ? 'blocked' : null,
        ));
        self::assertTrue($result->newState);
    }

    private function drainAsync(): void
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        $handled = 0;
        while ([] !== $envelopes = [...$transport->get()]) {
            foreach ($envelopes as $envelope) {
                $bus->dispatch($envelope->with(new ReceivedStamp('async')));
                $transport->ack($envelope);
                ++$handled;
            }
        }
        self::assertGreaterThan(0, $handled);
    }
}
