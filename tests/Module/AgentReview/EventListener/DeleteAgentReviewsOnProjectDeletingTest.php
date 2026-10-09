<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\EventListener;

use App\Module\AgentReview\EventListener\DeleteAgentReviewsOnProjectDeleting;
use App\Module\Project\Event\ProjectDeleting;
use App\Module\Project\Service\ProjectDeleter;
use App\Tests\Module\AgentReview\AgentReviewScenario;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DeleteAgentReviewsOnProjectDeletingTest extends KernelTestCase
{
    use AgentReviewScenario;

    private EntityManagerInterface $em;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
    }

    /** The listener runs alone here, so no cascade from the project, the card or the pull request can hide it. */
    public function test_the_listener_deletes_the_reviews_of_its_project_only(): void
    {
        $doomed = $this->makeProject('agent-review-listener-doomed');
        $spared = $this->makeProject('agent-review-listener-spared');
        $this->review($this->card($doomed), $this->pullRequest($doomed));
        $this->review($this->card($spared), $this->pullRequest($spared));
        $this->em->flush();

        self::assertSame(1, $this->countFor((string) $doomed->id));

        $listener = self::getContainer()->get(DeleteAgentReviewsOnProjectDeleting::class);
        self::assertInstanceOf(DeleteAgentReviewsOnProjectDeleting::class, $listener);
        $listener(new ProjectDeleting($doomed));

        self::assertSame(0, $this->countFor((string) $doomed->id));
        self::assertSame(1, $this->countFor((string) $spared->id));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM board_cards WHERE project_id = :id', ['id' => (string) $doomed->id]));
    }

    /** Covers the listener order the container uses, with the card and pull request deletes of the other modules. */
    public function test_deleting_a_project_with_reviews_succeeds(): void
    {
        $doomed = $this->makeProject('agent-review-delete-doomed');
        $spared = $this->makeProject('agent-review-delete-spared');
        $this->review($this->card($doomed), $this->pullRequest($doomed));
        $this->review($this->card($spared), $this->pullRequest($spared));
        $this->em->flush();
        $doomedId = (string) $doomed->id;

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($doomed);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM projects WHERE id = :id', ['id' => $doomedId]));
        self::assertSame(0, $this->countFor($doomedId));
        self::assertSame(1, $this->countFor((string) $spared->id));
    }

    private function countFor(string $projectId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM agent_reviews WHERE project_id = :id', ['id' => $projectId]);
    }
}
