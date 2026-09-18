<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\RenameBoardColumnCommand;
use App\Module\Board\Command\RenameBoardColumnHandler;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Event\BoardColumnRenamed;
use App\Module\Project\Entity\Project;
use App\Outbox\Entity\OutboxEvent;
use App\Outbox\Repository\OutboxEventRepository;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class WriteOutboxEventOnBoardColumnRenamedTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private RenameBoardColumnHandler $rename;
    private OutboxEventRepository $outbox;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $rename = self::getContainer()->get(RenameBoardColumnHandler::class);
        self::assertInstanceOf(RenameBoardColumnHandler::class, $rename);
        $this->rename = $rename;

        $outbox = self::getContainer()->get(OutboxEventRepository::class);
        self::assertInstanceOf(OutboxEventRepository::class, $outbox);
        $this->outbox = $outbox;

        $owner = new User(fullName: 'Riley', email: 'column-renamed-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'column-renamed-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
    }

    /** The reader of the outbox decodes these keys, so a drift drops the event. */
    public function test_a_rename_that_changes_the_slug_writes_the_payload_the_reader_decodes(): void
    {
        $next = $this->column($this->project, 'next');

        ($this->rename)(new RenameBoardColumnCommand($next, CardReporter::Human, 'Up next', $next->label));

        $row = $this->onlyRow();
        self::assertSame([
            'type' => 'board.column_renamed',
            'projectId' => (string) $this->project->id,
            'subject' => ['type' => 'board_column', 'id' => (string) $next->id],
            'actor' => 'human',
            'fromSlug' => 'next',
            'toSlug' => 'up-next',
        ], $this->decode($row));

        $topics = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);
        self::assertSame('board.column_renamed', $row->type);
        self::assertNotNull($this->project->id);
        self::assertSame($topics->forProject($this->project->id), $row->topic);
    }

    public function test_a_rename_that_keeps_the_slug_writes_no_row(): void
    {
        $next = $this->column($this->project, 'next');
        $dispatched = $this->countDispatches();

        ($this->rename)(new RenameBoardColumnCommand($next, CardReporter::Human, 'NEXT!', $next->label));

        // Guard: the rename happened and dispatched its event, so the empty
        // outbox below is the listener's choice.
        self::assertSame('NEXT!', $next->label);
        self::assertSame('next', $next->slug);
        self::assertSame(1, $dispatched->count);
        self::assertSame([], $this->rows());
    }

    public function test_a_rolled_back_rename_leaves_no_row(): void
    {
        $next = $this->column($this->project, 'next');
        $nextId = (string) $next->id;

        $pending = null;
        $em = $this->em;
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        // After the listener under test, which runs at the default priority.
        $dispatcher->addListener(BoardColumnRenamed::class, static function () use ($em, &$pending): never {
            $pending = array_values(array_filter(
                $em->getUnitOfWork()->getScheduledEntityInsertions(),
                static fn (object $entity): bool => $entity instanceof OutboxEvent,
            ));

            throw new \RuntimeException('the transaction failed after the rename');
        }, -10);

        try {
            ($this->rename)(new RenameBoardColumnCommand($next, CardReporter::Human, 'Up next', $next->label));
            self::fail('a failed transaction must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('the transaction failed after the rename', $e->getMessage());
        }

        // Guard: the listener ran and persisted its row before the rollback.
        self::assertIsArray($pending);
        self::assertCount(1, $pending);

        $connection = $this->em->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM outbox_events WHERE project_id = :id', [
            'id' => (string) $this->project->id,
        ]));
        self::assertSame('next', $connection->fetchOne('SELECT slug FROM board_columns WHERE id = :id', ['id' => $nextId]));
    }

    private function countDispatches(): \stdClass
    {
        $counter = new \stdClass();
        $counter->count = 0;
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(BoardColumnRenamed::class, static function () use ($counter): void {
            ++$counter->count;
        });

        return $counter;
    }

    /** @return array<string, mixed> */
    private function decode(OutboxEvent $row): array
    {
        $decoded = json_decode($row->payload, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function onlyRow(): OutboxEvent
    {
        $rows = $this->rows();
        self::assertCount(1, $rows);

        return $rows[0];
    }

    /** @return list<OutboxEvent> */
    private function rows(): array
    {
        return array_values($this->outbox->findBy(['project' => $this->project]));
    }
}
