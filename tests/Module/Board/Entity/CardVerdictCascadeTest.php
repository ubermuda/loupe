<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Entity;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\CardVerdict;
use App\Module\Board\Entity\CardVerdictDelivery;
use App\Module\Board\Entity\CardVerdictKind;
use App\Module\Board\Entity\SiteReviewCheckState;
use App\Module\Project\Service\ProjectDeleter;
use App\Tests\Module\Board\CardVerdictScenario;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The foreign keys carry the cleanup, so no listener has to know these tables. */
final class CardVerdictCascadeTest extends KernelTestCase
{
    use BoardToolScenario;
    use CardVerdictScenario;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_deleting_a_project_removes_its_verdicts_deliveries_and_check_states(): void
    {
        $project = $this->makeProject('verdict-cascade');
        $card = $this->card($project);
        $pullRequest = $this->linkedPullRequest($card, 7);
        $verdict = new CardVerdict($card, CardVerdictKind::Approve, $project->owner, '', []);
        $this->em->persist($verdict);
        $this->em->persist(new CardVerdictDelivery($verdict, $pullRequest));
        $this->em->persist(new SiteReviewCheckState($pullRequest, 'abc123', 'success', 0));
        $this->em->flush();
        $connection = $this->em->getConnection();
        $projectId = (string) $project->id;

        // Guard: without it the zero counts below also hold for a fixture that wrote nothing.
        foreach (['board_card_verdicts', 'board_card_verdict_deliveries', 'board_site_review_check_states'] as $table) {
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM '.$table), $table);
        }

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($project);

        foreach (['board_card_verdicts', 'board_card_verdict_deliveries', 'board_site_review_check_states'] as $table) {
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM '.$table), $table);
        }
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM board_cards WHERE project_id = :id', ['id' => $projectId]));
    }

    public function test_deleting_a_pull_request_row_removes_its_deliveries_and_check_state_and_keeps_the_verdict(): void
    {
        $project = $this->makeProject('verdict-cascade-pr');
        $card = $this->card($project);
        $pullRequest = $this->linkedPullRequest($card, 7);
        $verdict = new CardVerdict($card, CardVerdictKind::Approve, $project->owner, '', []);
        $this->em->persist($verdict);
        $this->em->persist(new CardVerdictDelivery($verdict, $pullRequest));
        $this->em->persist(new SiteReviewCheckState($pullRequest, 'abc123', 'success', 0));
        $this->em->flush();
        $connection = $this->em->getConnection();

        $connection->executeStatement('DELETE FROM forge_pull_requests WHERE id = :id', ['id' => (string) $pullRequest->id]);

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM board_card_verdict_deliveries'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM board_site_review_check_states'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM board_card_verdicts'));
    }

    public function test_deleting_the_reviewer_keeps_the_verdict_with_no_reviewer(): void
    {
        $project = $this->makeProject('verdict-cascade-user');
        $card = $this->card($project);
        $reviewer = new User(fullName: 'Sam', email: 'sam-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($reviewer);
        $verdict = new CardVerdict($card, CardVerdictKind::Approve, $reviewer, '', []);
        $this->em->persist($verdict);
        $this->em->flush();
        $connection = $this->em->getConnection();
        self::assertSame((string) $reviewer->id, $connection->fetchOne('SELECT reviewer_id FROM board_card_verdicts WHERE id = :id', ['id' => (string) $verdict->id]));

        $connection->executeStatement('DELETE FROM users WHERE id = :id', ['id' => (string) $reviewer->id]);

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM board_card_verdicts WHERE id = :id', ['id' => (string) $verdict->id]));
        self::assertNull($connection->fetchOne('SELECT reviewer_id FROM board_card_verdicts WHERE id = :id', ['id' => (string) $verdict->id]));
    }
}
