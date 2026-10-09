<?php

declare(strict_types=1);

namespace App\Tests\Module\Board;

use App\Module\Board\Command\ConfigureBoardColumnCommand;
use App\Module\Board\Command\ConfigureBoardColumnHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Service\BoardColumnSeeder;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The four seeded columns for a project a test persists by hand. A hand-built
 * project dispatches no creation event, so nothing else seeds them.
 */
trait BoardColumnFixtures
{
    /** @var array<string, BoardColumn> project id and slug => column */
    private array $seededColumns = [];

    /** Persists the four columns and does not flush, so they commit with the project. */
    private function seedColumns(Project $project): void
    {
        $seeder = self::getContainer()->get(BoardColumnSeeder::class);
        self::assertInstanceOf(BoardColumnSeeder::class, $seeder);

        foreach ($seeder->seed($project) as $column) {
            $this->seededColumns[$project->id.'/'.$column->slug] = $column;
        }
    }

    /**
     * The project's column with that slug. The copy seeded in this test comes
     * first, so the lookup also works before a flush and after a kernel reboot.
     */
    private function column(Project $project, string $slug): BoardColumn
    {
        $repository = self::getContainer()->get(BoardColumnRepository::class);
        self::assertInstanceOf(BoardColumnRepository::class, $repository);

        $managed = null === $project->id ? null : $repository->findOneBy(['project' => $project->id, 'slug' => $slug]);

        return $managed ?? $this->seededColumns[$project->id.'/'.$slug] ?? throw new \LogicException(\sprintf('The project has no column "%s".', $slug));
    }

    /**
     * An open column the board draws first, where the Backlog stood before the
     * board stopped drawing it, for a test that reads four drawn columns.
     */
    private function addTriageColumn(Project $project): BoardColumn
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $this->column($project, 'backlog')->position = -1;
        $triage = new BoardColumn(project: $project, label: 'Triage', slug: 'triage', position: 0);
        $em->persist($triage);
        $this->seededColumns[$project->id.'/triage'] = $triage;

        return $triage;
    }

    /** Saves one column's configure dialog. A field left out keeps what the column holds. */
    private function configureColumn(Project $project, string $slug, ?string $label = null, ?bool $terminal = null): void
    {
        $container = self::getContainer();
        $handler = $container->get(ConfigureBoardColumnHandler::class);
        self::assertInstanceOf(ConfigureBoardColumnHandler::class, $handler);
        $translator = $container->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $column = $this->column($project, $slug);

        $handler(new ConfigureBoardColumnCommand(
            $column,
            Actor::Human,
            $label ?? $translator->trans($column->label),
            $terminal ?? $column->terminal,
            $column->label,
            $column->terminal,
        ));
    }
}
