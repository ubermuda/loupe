<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\EventListener;

use App\Module\Insights\Entity\InsightsBucketRule;
use App\Module\Insights\Entity\InsightsProjectSettings;
use App\Module\Insights\EventListener\DeleteInsightsDataOnProjectDeleting;
use App\Module\Project\Entity\Project;
use App\Module\Project\Event\ProjectDeleting;
use App\Module\Project\Service\ProjectDeleter;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DeleteInsightsDataOnProjectDeletingTest extends KernelTestCase
{
    use InsightsScenario;

    private const array TABLES = ['insights_analyses', 'insights_proposals', 'insights_project_settings', 'insights_bucket_rules'];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    /** The listener runs alone here, so no cascade from the project row can hide a table it forgot. */
    public function test_the_listener_deletes_the_insights_rows_of_its_project_only(): void
    {
        $doomed = $this->seedInsights('insights-listener-doomed');
        $spared = $this->seedInsights('insights-listener-spared');

        $listener = self::getContainer()->get(DeleteInsightsDataOnProjectDeleting::class);
        self::assertInstanceOf(DeleteInsightsDataOnProjectDeleting::class, $listener);
        $listener(new ProjectDeleting($doomed));

        self::assertSame(array_fill_keys(self::TABLES, 0), $this->counts($doomed));
        self::assertSame(array_fill_keys(self::TABLES, 1), $this->counts($spared));
    }

    public function test_deleting_a_project_with_insights_rows_succeeds(): void
    {
        $doomed = $this->seedInsights('insights-project-delete');
        $doomedId = (string) $doomed->id;

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($doomed);

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM projects WHERE id = ?', [$doomedId]));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM insights_analyses WHERE project_id = ?', [$doomedId]));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM insights_bucket_rules WHERE project_id = ?', [$doomedId]));
    }

    private function seedInsights(string $name): Project
    {
        $em = $this->em();
        $project = $this->scenarioProject($name);
        $this->seedProposal($em, $this->seedAnalysis($em, $project));
        $em->persist(new InsightsProjectSettings($project));
        $em->persist(new InsightsBucketRule($project, 'Bash:git *', 'git', 0));
        $em->flush();

        return $project;
    }

    /** @return array<string, int> */
    private function counts(Project $project): array
    {
        $connection = $this->em()->getConnection();
        $id = (string) $project->id;

        return [
            'insights_analyses' => (int) $connection->fetchOne('SELECT COUNT(*) FROM insights_analyses WHERE project_id = ?', [$id]),
            'insights_proposals' => (int) $connection->fetchOne('SELECT COUNT(*) FROM insights_proposals p JOIN insights_analyses a ON a.id = p.analysis_id WHERE a.project_id = ?', [$id]),
            'insights_project_settings' => (int) $connection->fetchOne('SELECT COUNT(*) FROM insights_project_settings WHERE project_id = ?', [$id]),
            'insights_bucket_rules' => (int) $connection->fetchOne('SELECT COUNT(*) FROM insights_bucket_rules WHERE project_id = ?', [$id]),
        ];
    }
}
