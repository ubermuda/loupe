<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\DeleteBoardColumnCommand;
use App\Module\Board\Command\DeleteBoardColumnHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Event\BoardColumnDeleted;
use App\Module\Project\Entity\Project;
use App\Outbox\Entity\OutboxEvent;
use App\Outbox\Repository\OutboxEventRepository;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class WriteOutboxEventOnBoardColumnDeletedTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private DeleteBoardColumnHandler $delete;
    private OutboxEventRepository $outbox;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $delete = self::getContainer()->get(DeleteBoardColumnHandler::class);
        self::assertInstanceOf(DeleteBoardColumnHandler::class, $delete);
        $this->delete = $delete;

        $outbox = self::getContainer()->get(OutboxEventRepository::class);
        self::assertInstanceOf(OutboxEventRepository::class, $outbox);
        $this->outbox = $outbox;

        $owner = new User(fullName: 'Riley', email: 'column-deleted-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'column-deleted-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
    }

    /** The reader of the outbox decodes these keys, so a drift drops the event. */
    public function test_an_empty_column_writes_one_row_with_no_target_and_no_cards(): void
    {
        $next = $this->column($this->project, 'next');
        $nextId = (string) $next->id;

        ($this->delete)(new DeleteBoardColumnCommand($next, CardReporter::Human));

        $row = $this->onlyRow();
        self::assertSame([
            'type' => 'board.column_deleted',
            'projectId' => (string) $this->project->id,
            'subject' => ['type' => 'board_column', 'id' => $nextId],
            'actor' => 'human',
            'slug' => 'next',
            'targetSlug' => null,
            'movedCardIds' => [],
        ], $this->decode($row));

        $topics = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);
        self::assertSame('board.column_deleted', $row->type);
        self::assertNotNull($this->project->id);
        self::assertSame($topics->forProject($this->project->id), $row->topic);
    }

    public function test_an_empty_column_sends_no_target_even_when_one_is_given(): void
    {
        ($this->delete)(new DeleteBoardColumnCommand($this->column($this->project, 'next'), CardReporter::Human, $this->column($this->project, 'in-progress')));

        $payload = $this->decode($this->onlyRow());
        self::assertSame('next', $payload['slug']);
        self::assertArrayHasKey('targetSlug', $payload);
        self::assertNull($payload['targetSlug']);
        self::assertSame([], $payload['movedCardIds']);
    }

    public function test_a_column_with_cards_writes_one_row_that_names_the_target_and_every_moved_card(): void
    {
        $first = (string) $this->card('First', CardPriority::High)->id;
        $second = (string) $this->card('Second', CardPriority::Low)->id;
        $next = $this->column($this->project, 'next');
        $nextId = (string) $next->id;

        ($this->delete)(new DeleteBoardColumnCommand($next, CardReporter::Human, $this->column($this->project, 'in-progress')));

        self::assertSame([
            'type' => 'board.column_deleted',
            'projectId' => (string) $this->project->id,
            'subject' => ['type' => 'board_column', 'id' => $nextId],
            'actor' => 'human',
            'slug' => 'next',
            'targetSlug' => 'in-progress',
            'movedCardIds' => [$first, $second],
        ], $this->decode($this->onlyRow()));
    }

    public function test_a_rolled_back_delete_leaves_no_row(): void
    {
        $this->card('Stays put', CardPriority::Medium);
        $next = $this->column($this->project, 'next');
        $nextId = (string) $next->id;

        $pending = null;
        $em = $this->em;
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        // After the listener under test, which runs at the default priority.
        $dispatcher->addListener(BoardColumnDeleted::class, static function () use ($em, &$pending): never {
            $pending = array_values(array_filter(
                $em->getUnitOfWork()->getScheduledEntityInsertions(),
                static fn (object $entity): bool => $entity instanceof OutboxEvent,
            ));

            throw new \RuntimeException('the transaction failed after the delete');
        }, -10);

        try {
            ($this->delete)(new DeleteBoardColumnCommand($next, CardReporter::Human, $this->column($this->project, 'backlog')));
            self::fail('a failed transaction must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('the transaction failed after the delete', $e->getMessage());
        }

        // Guard: the listener ran and persisted its row before the rollback.
        self::assertIsArray($pending);
        self::assertCount(1, $pending);

        $connection = $this->em->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM outbox_events WHERE project_id = :id', [
            'id' => (string) $this->project->id,
        ]));
        self::assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM board_cards WHERE column_id = :id', ['id' => $nextId]));
    }

    private function card(string $title, CardPriority $priority): Card
    {
        $create = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $create);

        return $create(new CreateCardCommand(
            project: $this->project,
            title: $title,
            body: 'Body',
            type: CardType::Bug,
            priority: $priority,
            column: $this->column($this->project, 'next'),
            reporter: CardReporter::Agent,
        ));
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
        $rows = array_values($this->outbox->findBy(['project' => $this->project]));
        self::assertCount(1, $rows);

        return $rows[0];
    }
}
