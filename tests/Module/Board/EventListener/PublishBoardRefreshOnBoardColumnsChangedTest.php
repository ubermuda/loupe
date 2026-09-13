<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Exception\DomainErrors;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\AddBoardColumnCommand;
use App\Module\Board\Command\AddBoardColumnHandler;
use App\Module\Board\Command\DeleteBoardColumnCommand;
use App\Module\Board\Command\DeleteBoardColumnHandler;
use App\Module\Board\Command\RenameBoardColumnCommand;
use App\Module\Board\Command\RenameBoardColumnHandler;
use App\Module\Board\Command\ReorderBoardColumnsCommand;
use App\Module\Board\Command\ReorderBoardColumnsHandler;
use App\Module\Board\Command\SetBoardColumnTerminalCommand;
use App\Module\Board\Command\SetBoardColumnTerminalHandler;
use App\Module\Board\Command\SetDefaultBoardColumnCommand;
use App\Module\Board\Command\SetDefaultBoardColumnHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Event\BoardColumnRenamed;
use App\Module\Board\Event\BoardColumnsChanged;
use App\Module\Board\EventListener\PublishBoardRefreshOnBoardColumnsChanged;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Project\Entity\Project;
use App\Outbox\AgentPush;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\FeatureFlags;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

final class PublishBoardRefreshOnBoardColumnsChangedTest extends KernelTestCase
{
    use BoardColumnFixtures;

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

        $owner = new User(fullName: 'Riley', email: 'board-refresh-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'board-refresh-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
    }

    public function test_each_column_change_publishes_one_refresh_to_the_board_topic(): void
    {
        $added = $this->handler(AddBoardColumnHandler::class)(new AddBoardColumnCommand($this->project, 'Parked'));
        $this->assertPublishedCount(1);

        $this->handler(RenameBoardColumnHandler::class)(new RenameBoardColumnCommand($added, CardReporter::Human, 'On hold'));
        $this->assertPublishedCount(2);

        $order = array_map(static fn (BoardColumn $column): string => (string) $column->id, array_reverse($this->columns()));
        $this->handler(ReorderBoardColumnsHandler::class)(new ReorderBoardColumnsCommand($this->project, implode(',', $order)));
        $this->assertPublishedCount(3);

        $this->handler(SetBoardColumnTerminalHandler::class)(new SetBoardColumnTerminalCommand($added, true));
        $this->assertPublishedCount(4);

        $this->handler(SetDefaultBoardColumnHandler::class)(new SetDefaultBoardColumnCommand($this->column($this->project, 'next')));
        $this->assertPublishedCount(5);

        $this->handler(DeleteBoardColumnHandler::class)(new DeleteBoardColumnCommand($added, CardReporter::Human));
        $this->assertPublishedCount(6);

        $projectId = $this->project->id;
        self::assertNotNull($projectId);
        $boardTopic = $this->topics()->forBoard($projectId);
        foreach ($this->published as $update) {
            self::assertSame([$boardTopic], $update->getTopics());
            self::assertTrue($update->isPrivate());
            // No label: what a board shows depends on who looks at it.
            self::assertSame('{"type":"board.columns_changed"}', $update->getData());
        }
    }

    public function test_a_change_that_changes_nothing_or_is_refused_publishes_nothing(): void
    {
        $backlog = $this->column($this->project, 'backlog');
        $this->handler(SetDefaultBoardColumnHandler::class)(new SetDefaultBoardColumnCommand($backlog));

        try {
            $this->handler(RenameBoardColumnHandler::class)(new RenameBoardColumnCommand($backlog, CardReporter::Human, 'Next'));
            self::fail('a rename onto a slug the board already holds must be refused');
        } catch (DomainErrors) {
        }

        // Guard: the default is where the no-op left it, so nothing moved.
        self::assertTrue($backlog->isDefault);
        $this->assertPublishedCount(0);
    }

    public function test_a_rolled_back_add_publishes_nothing(): void
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
            $this->handler(AddBoardColumnHandler::class)(new AddBoardColumnCommand($this->project, 'Parked'));
            self::fail('a failed transaction must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('the transaction failed after the flush', $e->getMessage());
        } finally {
            $this->em->getEventManager()->removeEventListener(Events::postFlush, $failing);
        }

        self::assertTrue($failing->ran);
        $this->assertBoardUnchangedAndNothingPublished();
    }

    public function test_a_rolled_back_rename_publishes_nothing(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(BoardColumnRenamed::class, static function (): never {
            throw new \RuntimeException('the transaction failed after the rename');
        });

        try {
            $this->handler(RenameBoardColumnHandler::class)(new RenameBoardColumnCommand($this->column($this->project, 'next'), CardReporter::Human, 'Up next'));
            self::fail('a failed transaction must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('the transaction failed after the rename', $e->getMessage());
        }

        $this->assertBoardUnchangedAndNothingPublished();
    }

    public function test_with_push_off_it_neither_builds_the_hub_nor_publishes(): void
    {
        $listener = new PublishBoardRefreshOnBoardColumnsChanged(
            $this->topics(),
            FeatureFlags::service([AgentPush::FLAG => false]),
            new NullLogger(),
            static fn (): HubInterface => throw new \LogicException('the hub must not be built with push off'),
        );

        $listener(new BoardColumnsChanged($this->project));

        $this->assertPublishedCount(0);
    }

    public function test_a_hub_that_fails_is_logged_and_the_change_stands(): void
    {
        $log = new TestHandler();
        $listener = new PublishBoardRefreshOnBoardColumnsChanged(
            $this->topics(),
            FeatureFlags::service([AgentPush::FLAG => true]),
            new Logger('test', [$log]),
            static fn (): HubInterface => new MockHub(
                'http://mercure/.well-known/mercure',
                new StaticTokenProvider('token'),
                static fn (): string => throw new \RuntimeException('hub unreachable'),
            ),
        );

        $listener(new BoardColumnsChanged($this->project));

        self::assertTrue($log->hasWarning([
            'message' => 'board.refresh_publish_failed',
            'context' => ['projectId' => (string) $this->project->id, 'error' => 'hub unreachable'],
        ]));
    }

    private function assertBoardUnchangedAndNothingPublished(): void
    {
        $this->assertPublishedCount(0);
        $connection = $this->em->getConnection();
        self::assertSame(4, (int) $connection->fetchOne('SELECT count(*) FROM board_columns WHERE project_id = :id', [
            'id' => (string) $this->project->id,
        ]));
        self::assertSame('next', $connection->fetchOne('SELECT slug FROM board_columns WHERE project_id = :id AND position = 1', [
            'id' => (string) $this->project->id,
        ]));
    }

    private function assertPublishedCount(int $expected): void
    {
        self::assertCount($expected, $this->published);
    }

    private function recordingHub(): HubInterface
    {
        return new MockHub(
            'http://mercure/.well-known/mercure',
            new StaticTokenProvider('token'),
            function (Update $update): string {
                $this->published[] = $update;

                return 'id';
            },
        );
    }

    /** @return list<BoardColumn> */
    private function columns(): array
    {
        return $this->handler(BoardColumnRepository::class)->findForProject($this->project);
    }

    private function topics(): ProjectTopicBuilder
    {
        $topics = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);

        return $topics;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function handler(string $class): object
    {
        $handler = self::getContainer()->get($class);
        self::assertInstanceOf($class, $handler);

        return $handler;
    }
}
