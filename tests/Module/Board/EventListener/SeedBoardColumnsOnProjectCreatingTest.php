<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Doctrine\SearchLanguage;
use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Project\Command\CreateProjectCommand;
use App\Module\Project\Command\CreateProjectHandler;
use App\Module\Project\Command\EnsureHarnessProjectCommand;
use App\Module\Project\Command\EnsureHarnessProjectHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Event\ProjectCreating;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SeedBoardColumnsOnProjectCreatingTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BoardColumnRepository $columns;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $columns = self::getContainer()->get(BoardColumnRepository::class);
        self::assertInstanceOf(BoardColumnRepository::class, $columns);
        $this->columns = $columns;
    }

    public function test_a_created_project_gets_the_four_columns_in_board_order(): void
    {
        $handler = self::getContainer()->get(CreateProjectHandler::class);
        self::assertInstanceOf(CreateProjectHandler::class, $handler);

        $project = $handler(new CreateProjectCommand($this->owner(), 'columns-'.uniqid(), null, SearchLanguage::English));
        $this->em->clear();

        self::assertSame(
            [
                ['backlog', 'board.card.status.backlog', 0, false, true],
                ['next', 'board.card.status.next', 1, false, false],
                ['in-progress', 'board.card.status.in-progress', 2, false, false],
                ['done', 'board.card.status.done', 3, true, false],
            ],
            $this->describe($project),
        );
    }

    public function test_a_harness_project_gets_its_columns(): void
    {
        // Built by hand: only dev-only controllers inject it, so the test
        // container inlines it away.
        $projects = self::getContainer()->get(ProjectRepository::class);
        self::assertInstanceOf(ProjectRepository::class, $projects);
        $handler = new EnsureHarnessProjectHandler($projects, $this->em, $this->events());

        $project = $handler(new EnsureHarnessProjectCommand($this->owner(), 'harness'));
        $this->em->clear();

        self::assertCount(4, $this->describe($project));
    }

    /** The listener persists and never flushes, so a project flush that fails takes the columns with it. */
    public function test_the_columns_roll_back_with_a_project_flush_that_fails(): void
    {
        $owner = $this->owner();
        $project = new Project($owner, 'rollback-'.uniqid());
        $this->em->persist($project);
        $this->events()->dispatch(new ProjectCreating($project));
        // Same owner and name, so uniq_project_owner_name refuses the flush.
        $this->em->persist(new Project($owner, $project->name));

        try {
            $this->em->flush();
            self::fail('Expected the duplicate project to be refused.');
        } catch (UniqueConstraintViolationException) {
        }

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM board_columns WHERE project_id = :id',
            ['id' => (string) $project->id],
        ));
    }

    private function events(): EventDispatcherInterface
    {
        $events = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $events);

        return $events;
    }

    /** @return list<array{string, string, int, bool, bool}> */
    private function describe(Project $project): array
    {
        $fresh = $this->em->find(Project::class, $project->id);
        self::assertInstanceOf(Project::class, $fresh);

        return array_map(
            static fn (BoardColumn $column): array => [$column->slug, $column->label, $column->position, $column->terminal, $column->isDefault],
            $this->columns->findForProject($fresh),
        );
    }

    private function owner(): User
    {
        $owner = new User(fullName: 'Riley', email: 'board-seed-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->em->flush();

        return $owner;
    }
}
