<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Mercure\LiveUpdates;
use App\Mercure\UserTopicBuilder;
use App\Module\Board\Command\DeleteBoardColumnCommand;
use App\Module\Board\Command\DeleteBoardColumnHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Event\CardMoved;
use App\Module\Inbox\Command\AnswerInboxItemCommand;
use App\Module\Inbox\Command\AnswerInboxItemHandler;
use App\Module\Inbox\Command\AskInboxCommand;
use App\Module\Inbox\Command\AskInboxHandler;
use App\Module\Inbox\Command\AskInboxItem;
use App\Module\Inbox\Command\DeclineInboxItemCommand;
use App\Module\Inbox\Command\DeclineInboxItemHandler;
use App\Module\Inbox\Command\JoinInboxAskCommand;
use App\Module\Inbox\Command\JoinInboxAskHandler;
use App\Module\Inbox\Command\MarkInboxItemDoneCommand;
use App\Module\Inbox\Command\MarkInboxItemDoneHandler;
use App\Module\Inbox\Command\WithdrawInboxItemCommand;
use App\Module\Inbox\Command\WithdrawInboxItemHandler;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\InboxOpenCountPublisher;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxFixtures;
use App\Tests\Support\FeatureFlags;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

final class InboxOpenCountPublisherTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private Project $project;

    /** @var list<Update> */
    private array $published = [];

    protected function setUp(): void
    {
        self::bootKernel();

        // Before anything builds the hub: the container hands the replacement
        // only to what it builds afterwards.
        self::getContainer()->set('mercure.hub.default', $this->recordingHub());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
        $this->switchFlag($em, LiveUpdates::FLAG, true);

        $this->project = $this->project($em, $this->owner($em, 'inbox-count'), 'inbox-count');
        $em->flush();
    }

    public function test_an_ask_signals_the_owner_inbox_topic_at_terminate(): void
    {
        $this->service(AskInboxHandler::class)(new AskInboxCommand($this->project, Uuid::v4(), [
            new AskInboxItem(InboxItemKind::Question, 'Which column?', freeText: true),
            new AskInboxItem(InboxItemKind::Todo, 'Review pull request 482'),
        ]));

        self::assertCount(0, $this->published);
        // One signal for the call, however many items it opened.
        $this->assertPublishedAtTerminate(1);
        // No count, which could arrive out of order, and no text an agent wrote.
        $this->assertSignalsTheProject($this->published[0]);
    }

    public function test_an_answer_a_done_and_a_decline_each_signal(): void
    {
        $question = $this->item($this->em, $this->project, 1);
        $todo = new InboxItem(project: $this->project, number: 2, kind: InboxItemKind::Todo, title: 'Review', blocking: false);
        $this->em->persist($todo);
        $declined = $this->item($this->em, $this->project, 3);
        $this->em->flush();

        $this->service(AnswerInboxItemHandler::class)(new AnswerInboxItemCommand($question, '0', ''));
        $this->assertPublishedAtTerminate(1);
        $this->service(MarkInboxItemDoneHandler::class)(new MarkInboxItemDoneCommand($todo));
        $this->assertPublishedAtTerminate(2);
        $this->service(DeclineInboxItemHandler::class)(new DeclineInboxItemCommand($declined, ''));
        $this->assertPublishedAtTerminate(3);

        foreach ($this->published as $update) {
            $this->assertSignalsTheProject($update);
        }
    }

    public function test_a_withdraw_publishes(): void
    {
        $item = $this->item($this->em, $this->project, 1);
        $this->em->flush();

        $this->service(WithdrawInboxItemHandler::class)(new WithdrawInboxItemCommand($item, 'Settled in chat'));

        $this->assertPublishedAtTerminate(1);
        $this->assertSignalsTheProject($this->published[0]);
    }

    public function test_a_changed_answer_and_a_join_leave_the_count_alone_and_publish_nothing(): void
    {
        $item = $this->item($this->em, $this->project, 1);
        $this->em->flush();
        $this->service(AnswerInboxItemHandler::class)(new AnswerInboxItemCommand($item, '0', ''));
        $this->assertPublishedAtTerminate(1);

        $this->service(AnswerInboxItemHandler::class)(new AnswerInboxItemCommand($item, '1', ''));
        $open = $this->item($this->em, $this->project, 2);
        $this->em->flush();
        $this->assertPublishedAtTerminate(1);
        $joined = $this->service(JoinInboxAskHandler::class)(new JoinInboxAskCommand($open, Uuid::v4()));

        // Guard: both calls wrote, so only the publish stayed away.
        self::assertTrue($joined->added);
        self::assertSame([1], $this->reload($item)->selectedOptions);
        $this->assertPublishedAtTerminate(1);
    }

    public function test_a_card_move_that_makes_an_item_obsolete_publishes_after_the_move_commits(): void
    {
        $card = $this->card($this->em, $this->project);
        $item = $this->linkedItem(1, $card);
        $this->em->flush();

        $this->move($card, 'done');

        self::assertSame(InboxItemState::Obsolete, $this->reload($item)->state);
        $this->assertPublishedAtTerminate(1);
        $this->assertSignalsTheProject($this->published[0]);
    }

    public function test_a_column_delete_that_makes_an_item_obsolete_publishes(): void
    {
        $card = new Card(project: $this->project, column: $this->column($this->project, 'in-progress'), title: 'Moves', body: 'Body', number: 1);
        $this->em->persist($card);
        $item = $this->linkedItem(1, $card);
        $this->em->flush();

        $this->service(DeleteBoardColumnHandler::class)(new DeleteBoardColumnCommand($this->column($this->project, 'in-progress'), CardReporter::Human, $this->column($this->project, 'done')));

        self::assertSame(InboxItemState::Obsolete, $this->reload($item)->state);
        $this->assertPublishedAtTerminate(1);
    }

    public function test_a_card_move_that_rolls_back_publishes_nothing(): void
    {
        $card = $this->card($this->em, $this->project);
        $item = $this->linkedItem(1, $card);
        $this->em->flush();

        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $closedFirst = false;
        // After the obsolete listener, so the item closes before the move fails.
        $dispatcher->addListener(CardMoved::class, function () use ($item, &$closedFirst): never {
            $closedFirst = InboxItemState::Obsolete === $item->state;

            throw new \RuntimeException('the move failed after the item closed');
        }, -100);

        try {
            $this->move($card, 'done');
            self::fail('a failed move must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('the move failed after the item closed', $e->getMessage());
        }

        self::assertTrue($closedFirst);
        $this->assertNothingPublishedAndStillOpen($item);
    }

    public function test_an_answer_that_rolls_back_publishes_nothing(): void
    {
        $item = $this->item($this->em, $this->project, 1);
        $this->em->flush();
        $failing = $this->failingFlush();

        try {
            $this->service(AnswerInboxItemHandler::class)(new AnswerInboxItemCommand($item, '0', ''));
            self::fail('a failed transaction must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('the transaction failed after the flush', $e->getMessage());
        } finally {
            $this->em->getEventManager()->removeEventListener(Events::postFlush, $failing);
        }

        self::assertTrue($failing->ran);
        $this->assertNothingPublishedAndStillOpen($item);
    }

    public function test_an_ask_that_rolls_back_publishes_nothing(): void
    {
        $failing = $this->failingFlush();

        try {
            $this->service(AskInboxHandler::class)(new AskInboxCommand($this->project, Uuid::v4(), [
                new AskInboxItem(InboxItemKind::Question, 'Which column?', freeText: true),
            ]));
            self::fail('a failed transaction must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('the transaction failed after the flush', $e->getMessage());
        } finally {
            $this->em->getEventManager()->removeEventListener(Events::postFlush, $failing);
        }

        self::assertTrue($failing->ran);
        $this->assertPublishedAtTerminate(0);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM inbox_items WHERE project_id = ?', [(string) $this->project->id]));
    }

    public function test_with_live_updates_off_it_neither_builds_the_hub_nor_publishes(): void
    {
        $publisher = new InboxOpenCountPublisher(
            $this->topics(),
            $this->service(InboxItemRepository::class),
            FeatureFlags::service([LiveUpdates::FLAG => false, InboxInstallFlags::FLAG_INBOX_ENABLED => true]),
            new NullLogger(),
            static fn (): HubInterface => throw new \LogicException('the hub must not be built with live updates off'),
        );

        $publisher->countChanged($this->project);
        $publisher->publish();

        self::assertCount(0, $this->published);
    }

    public function test_a_hub_that_fails_is_logged_and_the_change_stands(): void
    {
        $log = new TestHandler();
        $publisher = new InboxOpenCountPublisher(
            $this->topics(),
            $this->service(InboxItemRepository::class),
            FeatureFlags::service([LiveUpdates::FLAG => true]),
            new Logger('test', [$log]),
            static fn (): HubInterface => new MockHub(
                'http://mercure/.well-known/mercure',
                new StaticTokenProvider('token'),
                static fn (): string => throw new \RuntimeException('hub unreachable'),
            ),
        );

        $publisher->countChanged($this->project);
        $publisher->publish();

        self::assertTrue($log->hasWarning([
            'message' => 'inbox.open_count_publish_failed',
            'context' => ['projectId' => (string) $this->project->id, 'error' => 'hub unreachable'],
        ]));
    }

    private function assertNothingPublishedAndStillOpen(InboxItem $item): void
    {
        $this->assertPublishedAtTerminate(0);
        self::assertSame('open', $this->em->getConnection()->fetchOne('SELECT state FROM inbox_items WHERE id = ?', [(string) $item->id]));
    }

    /** Ends the request the way the kernel does, then counts every publish so far. */
    private function assertPublishedAtTerminate(int $expected): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        self::assertNotNull(self::$kernel);
        $dispatcher->dispatch(new TerminateEvent(self::$kernel, Request::create('/'), new Response()), KernelEvents::TERMINATE);

        self::assertCount($expected, $this->published);
    }

    private function assertSignalsTheProject(Update $update): void
    {
        $ownerId = $this->project->owner->id ?? throw new \LogicException('The owner has no id.');
        self::assertSame([$this->topics()->forInbox($ownerId)], $update->getTopics());
        self::assertTrue($update->isPrivate());
        self::assertSame(\sprintf('{"type":"inbox.open_count_changed","projectId":"%s"}', $this->project->id), $update->getData());
    }

    /** @return object{ran: bool} */
    private function failingFlush(): object
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

        return $failing;
    }

    private function linkedItem(int $number, Card $card): InboxItem
    {
        $item = $this->item($this->em, $this->project, $number);
        $item->cards->add(new InboxItemCard($item, $card));

        return $item;
    }

    private function move(Card $card, string $slug): void
    {
        $this->service(UpdateCardHandler::class)(new UpdateCardCommand($card, CardReporter::Human, column: $this->column($this->project, $slug)));
    }

    private function reload(InboxItem $item): InboxItem
    {
        $this->em->clear();
        $fresh = $this->em->find(InboxItem::class, $item->id);
        self::assertInstanceOf(InboxItem::class, $fresh);

        return $fresh;
    }

    private function recordingHub(): HubInterface
    {
        return new MockHub(
            'http://mercure/.well-known/mercure',
            new StaticTokenProvider('token'),
            function (Update $update): string {
                // A column delete also refreshes the board, on a topic of its own.
                if (str_contains($update->getData(), InboxOpenCountPublisher::TYPE)) {
                    $this->published[] = $update;
                }

                return 'id';
            },
        );
    }

    private function topics(): UserTopicBuilder
    {
        return $this->service(UserTopicBuilder::class);
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
