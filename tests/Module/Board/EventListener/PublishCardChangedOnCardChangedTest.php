<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\LiveUpdates;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\AddFeedbackCommand;
use App\Module\Board\Command\AddFeedbackHandler;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\DeleteCardCommand;
use App\Module\Board\Command\DeleteCardHandler;
use App\Module\Board\Command\DeleteFeedbackCommand;
use App\Module\Board\Command\DeleteFeedbackHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Event\CardMoved;
use App\Module\Board\EventListener\PublishCardChangedOnCardChanged;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\SiteReview\Command\AddCommentCommand;
use App\Module\SiteReview\Command\AddCommentHandler;
use App\Module\SiteReview\Command\DeleteCommentCommand;
use App\Module\SiteReview\Command\DeleteCommentHandler;
use App\Module\SiteReview\Command\MarkSiteReviewCommentsAddressedCommand;
use App\Module\SiteReview\Command\MarkSiteReviewCommentsAddressedHandler;
use App\Module\SiteReview\Command\ReopenSiteReviewCommentCommand;
use App\Module\SiteReview\Command\ReopenSiteReviewCommentHandler;
use App\Module\SiteReview\Command\ResolveCommentCommand;
use App\Module\SiteReview\Command\ResolveCommentHandler;
use App\Module\SiteReview\Command\ResolveSiteReviewCommentCommand;
use App\Module\SiteReview\Command\ResolveSiteReviewCommentHandler;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\FeatureFlags;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;
use Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

/**
 * Drives every write path that changes a card face and reads what reaches the
 * hub, so a path that forgets to dispatch CardChanged shows here.
 */
final class PublishCardChangedOnCardChangedTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private Project $project;
    private MockHub $hub;

    /** @var list<Update> */
    private array $published = [];

    protected function setUp(): void
    {
        self::bootKernel();

        // Before anything builds the hub: the container hands the replacement
        // only to what it builds afterwards.
        $this->hub = new MockHub(
            'http://mercure/.well-known/mercure',
            new StaticTokenProvider('token'),
            function (Update $update): string {
                $this->published[] = $update;

                return 'id';
            },
        );
        self::getContainer()->set('mercure.hub.default', $this->hub);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->setBoardEnabled();

        $owner = new User(fullName: 'Riley', email: 'card-changed-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'card-changed-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
    }

    public function test_creating_a_card_publishes_created_with_content(): void
    {
        $card = $this->service(CreateCardHandler::class)(new CreateCardCommand($this->project, 'Fix the footer', 'body', CardType::Bug));

        self::assertSame([$this->message($card, 'created', true)], $this->drain());
    }

    public function test_an_update_publishes_updated_and_says_whether_the_content_changed(): void
    {
        $card = $this->card();
        $update = $this->service(UpdateCardHandler::class);

        $update(new UpdateCardCommand($card, CardReporter::Human, title: 'A new title'));
        self::assertSame([$this->message($card, 'updated', true)], $this->drain());

        $update(new UpdateCardCommand($card, CardReporter::Human, body: 'A new body'));
        self::assertSame([$this->message($card, 'updated', true)], $this->drain());

        $update(new UpdateCardCommand($card, CardReporter::Human, type: CardType::Tooling));
        self::assertSame([$this->message($card, 'updated', false)], $this->drain());

        $update(new UpdateCardCommand($card, CardReporter::Human, column: $this->column($this->project, 'next')));
        self::assertSame([$this->message($card, 'updated', false)], $this->drain());

        $update(new UpdateCardCommand($card, CardReporter::Human, pullRequestUrls: ['https://github.com/acme/app/pull/7']));
        self::assertSame([$this->message($card, 'updated', false)], $this->drain());
    }

    public function test_an_update_that_changes_nothing_publishes_nothing(): void
    {
        $card = $this->card();

        $this->service(UpdateCardHandler::class)(new UpdateCardCommand($card, CardReporter::Human, title: $card->title));

        self::assertSame([], $this->drain());
    }

    public function test_deleting_a_card_publishes_deleted(): void
    {
        $card = $this->card();
        $expected = $this->message($card, 'deleted', false);

        $this->service(DeleteCardHandler::class)(new DeleteCardCommand($card, CardReporter::Human));

        self::assertSame([$expected], $this->drain());
    }

    public function test_a_rolled_back_create_publishes_nothing(): void
    {
        $failing = new class {
            public bool $ran = false;

            public function postFlush(): never
            {
                $this->ran = true;

                throw new \RuntimeException('the transaction failed after the flush');
            }
        };
        $this->em->getEventManager()->addEventListener(Events::postFlush, $failing);

        try {
            $this->service(CreateCardHandler::class)(new CreateCardCommand($this->project, 'Lost', 'body', CardType::Bug));
            self::fail('a failed transaction must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('the transaction failed after the flush', $e->getMessage());
        } finally {
            $this->em->getEventManager()->removeEventListener(Events::postFlush, $failing);
        }

        self::assertTrue($failing->ran);
        self::assertSame([], $this->drain());
    }

    public function test_linking_a_document_to_a_card_and_unlinking_it_publish_updated(): void
    {
        $card = $this->card();

        $document = $this->service(CreateDocumentHandler::class)(new CreateDocumentCommand(
            $this->project,
            'Spec',
            '# Spec',
            workLinkIds: [(string) $card->id],
        ));
        self::assertSame([$this->message($card, 'updated', false)], $this->drain());

        $this->service(ReviseDocumentHandler::class)(new ReviseDocumentCommand($document, '# Spec v2', 'second', workLinkIds: []));
        self::assertSame([$this->message($card, 'updated', false)], $this->drain());
    }

    public function test_an_update_rolled_back_after_its_flush_publishes_nothing(): void
    {
        $card = $this->card();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(CardMoved::class, static function (): never {
            throw new \RuntimeException('the transaction failed after the move');
        });

        try {
            $this->service(UpdateCardHandler::class)(new UpdateCardCommand($card, CardReporter::Human, column: $this->column($this->project, 'next')));
            self::fail('a failed transaction must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('the transaction failed after the move', $e->getMessage());
        }

        self::assertSame([], $this->drain());
    }

    public function test_a_note_on_an_existing_card_publishes_updated(): void
    {
        $card = $this->card();
        $this->drain();

        $this->comment($card);

        self::assertSame([$this->message($card, 'updated', false)], $this->drain());
    }

    public function test_a_note_that_creates_its_card_publishes_created_alone(): void
    {
        $link = $this->service(AddFeedbackHandler::class)(new AddFeedbackCommand(
            $this->project,
            'the launcher overlaps the footer',
            'https://preview/x',
        ));

        // Guard: the note made the card, so the create is the only message.
        self::assertTrue($link->createdCard);
        self::assertSame([$this->message($link->card, 'created', true)], $this->drain());
    }

    public function test_each_status_change_of_a_linked_comment_publishes_updated(): void
    {
        $card = $this->card();
        $comment = $this->comment($card);
        $expected = [$this->message($card, 'updated', false)];
        $this->drain();

        $this->service(MarkSiteReviewCommentsAddressedHandler::class)(new MarkSiteReviewCommentsAddressedCommand([$comment]));
        self::assertSame($expected, $this->drain());

        $this->service(ReopenSiteReviewCommentHandler::class)(new ReopenSiteReviewCommentCommand($comment));
        self::assertSame($expected, $this->drain());

        $this->service(ResolveSiteReviewCommentHandler::class)(new ResolveSiteReviewCommentCommand($comment));
        self::assertSame($expected, $this->drain());

        $this->service(ReopenSiteReviewCommentHandler::class)(new ReopenSiteReviewCommentCommand($comment));
        $this->drain();
        $this->service(ResolveCommentHandler::class)(new ResolveCommentCommand($this->project, $comment->id ?? throw new \LogicException('Comment has no id.')));
        self::assertSame($expected, $this->drain());
    }

    public function test_marking_an_already_addressed_comment_publishes_nothing(): void
    {
        $card = $this->card();
        $comment = $this->comment($card);
        $mark = $this->service(MarkSiteReviewCommentsAddressedHandler::class);
        $mark(new MarkSiteReviewCommentsAddressedCommand([$comment]));
        // Guard: the first mark reached the hub, so the second one is the test.
        self::assertNotSame([], $this->drain());

        $mark(new MarkSiteReviewCommentsAddressedCommand([$comment]));

        self::assertSame([], $this->drain());
    }

    public function test_deleting_a_linked_comment_publishes_updated(): void
    {
        $card = $this->card();
        $comment = $this->comment($card);
        $this->drain();

        $this->service(DeleteCommentHandler::class)(new DeleteCommentCommand($this->project, $comment->id ?? throw new \LogicException('Comment has no id.')));

        self::assertSame([$this->message($card, 'updated', false)], $this->drain());
    }

    public function test_deleting_a_note_that_leaves_its_card_publishes_updated(): void
    {
        $card = $this->card();
        $comment = $this->comment($card);
        $this->drain();

        $cardDeleted = $this->service(DeleteFeedbackHandler::class)(new DeleteFeedbackCommand($this->project, $comment->id ?? throw new \LogicException('Comment has no id.')));

        // Guard: the card stays, so its face is what changed.
        self::assertFalse($cardDeleted);
        self::assertSame([$this->message($card, 'updated', false)], $this->drain());
    }

    public function test_a_status_change_of_an_unlinked_comment_publishes_nothing(): void
    {
        $comment = $this->comment(null);
        $this->drain();

        $this->service(ResolveSiteReviewCommentHandler::class)(new ResolveSiteReviewCommentCommand($comment));

        // Guard: the resolve ran.
        self::assertSame('Resolved', $comment->status->name);
        self::assertSame([], $this->drain());
    }

    public function test_the_listener_names_the_card_and_the_change_on_the_board_topic(): void
    {
        $publisher = new LiveUpdatePublisher(
            new RequestStack(),
            FeatureFlags::service([LiveUpdates::FLAG => true]),
            new NullLogger(),
            fn (): HubInterface => $this->hub,
        );
        $projectId = $this->project->id ?? throw new \LogicException('Project has no id.');
        $cardId = Uuid::v7();

        new PublishCardChangedOnCardChanged($this->service(ProjectTopicBuilder::class), $publisher)(new CardChanged($projectId, $cardId, CardChanged::DELETED, false));
        $publisher->publish();

        self::assertSame([[
            'type' => 'board.card_changed',
            'cardId' => (string) $cardId,
            'change' => 'deleted',
            'contentChanged' => false,
            'origin' => null,
        ]], $this->drain());
    }

    private function card(): Card
    {
        $card = new Card($this->project, $this->column($this->project, 'backlog'), 'Make the footer behave', 'body', 1);
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }

    private function comment(?Card $card): SiteReviewComment
    {
        if (null !== $card) {
            return $this->service(AddFeedbackHandler::class)(new AddFeedbackCommand(
                $this->project,
                'the launcher overlaps the footer',
                'https://preview/x',
                cardId: (string) $card->id,
            ))->comment;
        }

        return $this->service(AddCommentHandler::class)(new AddCommentCommand(
            $this->project,
            'the launcher overlaps the footer',
            'https://preview/x',
        ));
    }

    /** @return array{type: string, cardId: string, change: string, contentChanged: bool, origin: null} */
    private function message(Card $card, string $change, bool $contentChanged): array
    {
        return [
            'type' => 'board.card_changed',
            'cardId' => (string) $card->id,
            'change' => $change,
            'contentChanged' => $contentChanged,
            'origin' => null,
        ];
    }

    /**
     * Ends the request the way the kernel does, and answers the card messages
     * the board topic received since the last call.
     *
     * @return list<array<string, mixed>>
     */
    private function drain(): array
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        self::assertNotNull(self::$kernel);
        $dispatcher->dispatch(new TerminateEvent(self::$kernel, Request::create('/'), new Response()), KernelEvents::TERMINATE);

        $published = $this->published;
        $this->published = [];
        $boardTopic = $this->service(ProjectTopicBuilder::class)->forBoard($this->project->id ?? throw new \LogicException('Project has no id.'));

        $messages = [];
        foreach ($published as $update) {
            $data = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR);
            if (\is_array($data) && 'board.card_changed' === ($data['type'] ?? null)) {
                self::assertSame([$boardTopic], $update->getTopics());
                self::assertTrue($update->isPrivate());
                $messages[] = $data;
            }
        }

        return $messages;
    }

    private function setBoardEnabled(): void
    {
        $flags = $this->service(FeatureFlagRepository::class);
        $flags->findAllIndexed()[BoardInstallFlags::FLAG_BOARD_ENABLED]->value = true;
        $this->em->flush();

        $reader = self::getContainer()->get(FeatureFlagReaderInterface::class);
        self::assertInstanceOf(ResetInterface::class, $reader);
        $reader->reset();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
