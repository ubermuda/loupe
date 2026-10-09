<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\EventListener;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Event\BoardColumnDeleted;
use App\Module\Board\Event\BoardColumnTerminalChanged;
use App\Module\Board\Event\CardChanged;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Event\PullRequestStateChanged;
use App\Module\Inbox\Command\AskInboxCommand;
use App\Module\Inbox\Command\AskInboxHandler;
use App\Module\Inbox\Command\AskInboxItem;
use App\Module\Inbox\Command\AskInboxView;
use App\Module\Inbox\Command\DeclineInboxItemCommand;
use App\Module\Inbox\Command\DeclineInboxItemHandler;
use App\Module\Inbox\Command\MarkInboxItemsObsoleteCommand;
use App\Module\Inbox\Command\MarkInboxItemsObsoleteHandler;
use App\Module\Inbox\Command\WithdrawInboxItemCommand;
use App\Module\Inbox\Command\WithdrawInboxItemHandler;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Messenger\ReconcileCardWaits;
use App\Module\Inbox\Repository\InboxReviewRepository;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\SetDocumentTagsCommand;
use App\Module\Review\Command\SetDocumentTagsHandler;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Event\DocumentStatusChanged;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\PauseKind;
use App\Module\Workflow\Event\CardPaused;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/** Each trigger runs through the real dispatcher or handler, and the test reads what reached the async transport. */
final class ReconcileCardWaitsTriggersTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private InMemoryTransport $transport;
    private Project $project;
    private Card $card;
    private Card $other;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->transport = $transport;

        $this->project = $this->project($em, $this->owner($em, 'wait-triggers'), 'wait-triggers');
        $this->card = $this->card($em, $this->project, 1);
        $this->other = $this->card($em, $this->project, 2);
        $em->flush();
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
        $this->transport->reset();
    }

    public function test_every_card_change_asks_for_its_card(): void
    {
        foreach ([CardChanged::CREATED, CardChanged::UPDATED, CardChanged::DELETED] as $change) {
            $this->transport->reset();
            $this->dispatch(new CardChanged($this->projectId(), $this->cardId($this->card), $change, false));

            self::assertSame([[(string) $this->projectId(), [(string) $this->card->id]]], $this->sent(), $change);
        }
    }

    public function test_a_workflow_pause_asks_for_its_card(): void
    {
        $this->dispatch(new CardPaused($this->projectId(), $this->cardId($this->card), 'owner-review', PauseKind::Rule));

        self::assertSame([[(string) $this->projectId(), [(string) $this->card->id]]], $this->sent());
    }

    public function test_a_terminal_change_asks_for_the_cards_of_the_column(): void
    {
        $this->dispatch(new BoardColumnTerminalChanged($this->project, 'column', true, [(string) $this->card->id, (string) $this->other->id], Actor::Human));

        self::assertSame([[(string) $this->projectId(), [(string) $this->card->id, (string) $this->other->id]]], $this->sent());
    }

    public function test_a_column_delete_asks_for_the_moved_cards(): void
    {
        $this->dispatch(new BoardColumnDeleted($this->project, Uuid::v7()->toRfc4122(), 'review', 'backlog', [(string) $this->card->id], Actor::Human, false, false));

        self::assertSame([[(string) $this->projectId(), [(string) $this->card->id]]], $this->sent());
    }

    public function test_a_column_event_with_no_cards_asks_for_nothing(): void
    {
        $this->dispatch(new BoardColumnTerminalChanged($this->project, 'column', true, [], Actor::Human));
        $this->dispatch(new BoardColumnDeleted($this->project, Uuid::v7()->toRfc4122(), 'review', null, [], Actor::Human, false, false));

        self::assertSame([], $this->sent());
    }

    public function test_a_run_change_asks_for_its_cards(): void
    {
        $this->dispatch(new WorkerRunChanged($this->projectId(), [(string) $this->card->id, (string) $this->other->id], []));

        self::assertSame([[(string) $this->projectId(), $this->sortedIds($this->card, $this->other)]], $this->sent());
    }

    public function test_a_github_pull_request_state_change_asks_for_every_linked_card(): void
    {
        $this->em->persist(new CardPullRequest($this->card, 'https://github.com/Acme/Widgets/pull/5', Forge::GitHub, 'Acme/Widgets', 5));
        $this->em->persist(new CardPullRequest($this->other, 'https://github.com/acme/widgets/pull/5', Forge::GitHub, 'acme/widgets', 5));
        $pullRequest = new ForgePullRequest($this->project, 'github', 'acme/widgets', 5);
        $this->em->persist($pullRequest);
        $this->em->flush();

        $this->dispatch(new PullRequestStateChanged($pullRequest, $pullRequest->snapshot(), $pullRequest->snapshot()));

        self::assertSame([[(string) $this->projectId(), $this->sortedIds($this->card, $this->other)]], $this->sent());
    }

    public function test_a_pull_request_of_another_forge_asks_for_nothing(): void
    {
        $this->em->persist(new CardPullRequest($this->card, 'https://gitlab.com/acme/widgets/-/merge_requests/5', Forge::GitLab, 'acme/widgets', 5));
        $pullRequest = new ForgePullRequest($this->project, 'gitlab', 'acme/widgets', 5);
        $this->em->persist($pullRequest);
        $this->em->flush();

        $this->dispatch(new PullRequestStateChanged($pullRequest, $pullRequest->snapshot(), $pullRequest->snapshot()));

        self::assertSame([], $this->sent());
    }

    public function test_a_document_status_change_asks_for_every_linked_card(): void
    {
        $document = $this->linkedDocument($this->card, $this->other);

        $this->dispatch(new DocumentStatusChanged($this->projectId(), $this->documentId($document)));

        self::assertSame([[(string) $this->projectId(), $this->sortedIds($this->card, $this->other)]], $this->sent());
    }

    public function test_a_document_with_no_card_asks_for_nothing(): void
    {
        $document = $this->linkedDocument();

        $this->dispatch(new DocumentStatusChanged($this->projectId(), $this->documentId($document)));

        self::assertSame([], $this->sent());
    }

    public function test_a_tag_change_asks_for_every_linked_card(): void
    {
        $document = $this->linkedDocument($this->card, $this->other);

        $handler = self::getContainer()->get(SetDocumentTagsHandler::class);
        self::assertInstanceOf(SetDocumentTagsHandler::class, $handler);
        $handler(new SetDocumentTagsCommand($document, ['design', 'decisions']));

        self::assertSame([[(string) $this->projectId(), $this->sortedIds($this->card, $this->other)]], $this->sent());
    }

    public function test_a_verdict_asks_for_the_cards_of_the_document_even_with_an_open_agent_review(): void
    {
        $document = $this->linkedDocument($this->card);
        $this->agentReview($document);
        $this->transport->reset();

        $handler = self::getContainer()->get(SubmitReviewHandler::class);
        self::assertInstanceOf(SubmitReviewHandler::class, $handler);
        $handler(new SubmitReviewCommand($this->project->owner, $document, 'approved', 1));

        self::assertSame([[(string) $this->projectId(), [(string) $this->card->id]]], $this->sent());
    }

    public function test_an_ask_for_a_document_review_asks_for_the_cards_of_the_document(): void
    {
        $document = $this->linkedDocument($this->card);

        $this->agentReview($document);

        self::assertSame([[(string) $this->projectId(), [(string) $this->card->id]]], $this->sent());
    }

    public function test_an_ask_with_no_document_review_asks_for_nothing(): void
    {
        $document = $this->linkedDocument($this->card);

        $this->askInbox(new AskInboxItem(InboxItemKind::Todo, 'Read this', documentIds: [(string) $document->id]));

        self::assertSame([], $this->sent());
    }

    public function test_a_withdrawn_document_review_asks_for_the_cards_of_the_document(): void
    {
        $item = $this->agentReview($this->linkedDocument($this->card));
        $this->transport->reset();

        $handler = self::getContainer()->get(WithdrawInboxItemHandler::class);
        self::assertInstanceOf(WithdrawInboxItemHandler::class, $handler);
        $handler(new WithdrawInboxItemCommand($item, 'No longer needed'));

        self::assertSame([[(string) $this->projectId(), [(string) $this->card->id]]], $this->sent());
    }

    public function test_a_declined_document_review_asks_for_the_cards_of_the_document(): void
    {
        $item = $this->agentReview($this->linkedDocument($this->card));
        $this->transport->reset();

        $handler = self::getContainer()->get(DeclineInboxItemHandler::class);
        self::assertInstanceOf(DeclineInboxItemHandler::class, $handler);
        $handler(new DeclineInboxItemCommand($item, ''));

        self::assertSame([[(string) $this->projectId(), [(string) $this->card->id]]], $this->sent());
    }

    public function test_a_declined_question_asks_for_nothing(): void
    {
        $item = $this->item($this->em, $this->project);
        $this->em->flush();

        $handler = self::getContainer()->get(DeclineInboxItemHandler::class);
        self::assertInstanceOf(DeclineInboxItemHandler::class, $handler);
        $handler(new DeclineInboxItemCommand($item, ''));

        self::assertSame([], $this->sent());
    }

    public function test_an_obsolete_document_review_asks_for_every_card_of_the_document(): void
    {
        $item = $this->agentReview($this->linkedDocument($this->card, $this->other));
        $item->cards->add(new InboxItemCard($item, $this->card, new \DateTimeImmutable()));
        $this->card->column = $this->column($this->project, 'done');
        $this->em->flush();
        $this->transport->reset();

        $handler = self::getContainer()->get(MarkInboxItemsObsoleteHandler::class);
        self::assertInstanceOf(MarkInboxItemsObsoleteHandler::class, $handler);
        // The caller of the handler holds the transaction of the move.
        $closed = $this->em->wrapInTransaction(fn (): array => $handler(new MarkInboxItemsObsoleteCommand([(string) $this->card->id])));
        self::assertSame([$item], $closed);

        self::assertSame([[(string) $this->projectId(), $this->sortedIds($this->card, $this->other)]], $this->sent());
    }

    private function dispatch(object $event): void
    {
        $events = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        // Board dispatches its column events inside the transaction of the change.
        $this->em->wrapInTransaction(static fn (): object => $events->dispatch($event));
    }

    private function linkedDocument(Card ...$cards): Document
    {
        $document = $this->document($this->em, $this->project);
        $document->addVersion('# One', '<h1>One</h1>');
        foreach ($cards as $card) {
            $card->documents->add(new CardDocument($card, $document));
        }
        $this->em->flush();

        return $document;
    }

    private function agentReview(Document $document): InboxItem
    {
        $view = $this->askInbox(new AskInboxItem(InboxItemKind::Review, 'Review the design', blocking: true, reviewDocumentId: (string) $document->id));
        $item = $view->items[0];
        $reviews = self::getContainer()->get(InboxReviewRepository::class);
        self::assertInstanceOf(InboxReviewRepository::class, $reviews);
        self::assertNotNull($reviews->findOneBy(['item' => $item]));

        return $item;
    }

    private function askInbox(AskInboxItem $input): AskInboxView
    {
        $handler = self::getContainer()->get(AskInboxHandler::class);
        self::assertInstanceOf(AskInboxHandler::class, $handler);

        return $handler(new AskInboxCommand($this->project, Uuid::v4(), [$input]));
    }

    /** @return list<array{string, list<string>|null}> */
    private function sent(): array
    {
        $sent = [];
        foreach ($this->transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof ReconcileCardWaits) {
                $ids = $message->cardIds;
                if (null !== $ids) {
                    sort($ids);
                }
                $sent[] = [$message->projectId, $ids];
            }
        }

        return $sent;
    }

    /** @return list<string> */
    private function sortedIds(Card ...$cards): array
    {
        $ids = array_map(static fn (Card $card): string => (string) $card->id, $cards);
        sort($ids);

        return $ids;
    }

    private function projectId(): Uuid
    {
        return $this->project->id ?? throw new \LogicException('Project has no id.');
    }

    private function cardId(Card $card): Uuid
    {
        return $card->id ?? throw new \LogicException('Card has no id.');
    }

    private function documentId(Document $document): Uuid
    {
        return $document->id ?? throw new \LogicException('Document has no id.');
    }
}
