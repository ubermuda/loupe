<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Messenger;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\CardType;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

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
        $em->flush();
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);

        $createCard = $container->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $createCard);
        $card = $createCard(new CreateCardCommand($project, 'Ship it', 'Body', CardType::Feature, documentIds: [(string) $document->id]));
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
