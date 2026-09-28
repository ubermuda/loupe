<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Entity\BoardFixStrategy;
use App\Module\Board\Entity\BoardMergeStrategy;
use App\Module\Board\Repository\BoardAutomationSettingsRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BoardAutomationTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private BoardAutomation $automation;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $settings = self::getContainer()->get(BoardAutomationSettingsRepository::class);
        self::assertInstanceOf(BoardAutomationSettingsRepository::class, $settings);
        $this->automation = new BoardAutomation($settings, $em);
    }

    public function test_settings_of_a_project_with_no_row_are_the_unsaved_defaults(): void
    {
        $project = $this->makeProject('automation-defaults');

        $settings = $this->automation->settingsOf($project);
        $this->em->flush();

        self::assertTrue($settings->enabled);
        self::assertSame(BoardMergeStrategy::Worker, $settings->mergeStrategy);
        self::assertSame(BoardFixStrategy::Fresh, $settings->fixStrategy);
        self::assertSame(3, $settings->loopLimit);
        self::assertNull($settings->id);
        self::assertSame(0, $this->rowCount((string) $project->id));
    }

    public function test_settings_of_a_project_read_the_stored_row(): void
    {
        $project = $this->makeProject('automation-stored');
        $stored = $this->automation->settingsForUpdate($project);
        $stored->enabled = false;
        $stored->mergeStrategy = BoardMergeStrategy::Off;
        $stored->fixStrategy = BoardFixStrategy::Resume;
        $stored->loopLimit = 7;
        $this->em->flush();
        $this->em->clear();

        $settings = $this->automation->settingsOf($this->reloadProject($project));

        self::assertFalse($settings->enabled);
        self::assertSame(BoardMergeStrategy::Off, $settings->mergeStrategy);
        self::assertSame(BoardFixStrategy::Resume, $settings->fixStrategy);
        self::assertSame(7, $settings->loopLimit);
    }

    public function test_settings_for_update_creates_one_row_and_then_reuses_it(): void
    {
        $project = $this->makeProject('automation-update');

        $first = $this->automation->settingsForUpdate($project);
        $this->em->flush();
        self::assertSame(1, $this->rowCount((string) $project->id));
        $this->em->clear();

        $second = $this->automation->settingsForUpdate($this->reloadProject($project));
        $this->em->flush();

        self::assertNotNull($first->id);
        self::assertTrue($first->id->equals($second->id));
        self::assertSame(1, $this->rowCount((string) $project->id));
    }

    private function rowCount(string $projectId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM board_automation_settings WHERE project_id = :id',
            ['id' => $projectId],
        );
    }

    private function reloadProject(Project $project): Project
    {
        return $this->em->find(Project::class, $project->id) ?? throw new \LogicException('The project must exist.');
    }
}
