<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Tests\Module\Workflow\WorkflowProjects;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261007150100;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261007150100.php';

final class WorkflowRuleStateWorkRequestBackfillMigrationTest extends KernelTestCase
{
    use WorkflowProjects;

    public function test_a_rule_state_takes_the_live_request_of_its_rule_and_ignores_a_settled_one(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('backfill');
        $card = new Card($project, $this->column($project, 'in-progress'), 'Card', '', 1);
        $this->em()->persist($card);
        $cardId = $card->id ?? throw new \LogicException('The card is persisted.');
        $this->em()->persist($this->state($card, 'live'));
        $this->em()->persist($this->state($card, 'settled'));
        $live = $this->request($project, $card, 'live', '2026-10-02 12:00:00');
        $settled = $this->request($project, $card, 'settled', '2026-10-02 12:00:00');
        $settled->state = WorkRequestState::Done;
        $this->em()->flush();

        $migration = new Version20261007150100($this->em()->getConnection(), new NullLogger());
        $migration->up(new Schema());
        $backfill = array_values(array_filter($migration->getSql(), static fn ($query): bool => str_starts_with((string) $query->getStatement(), 'UPDATE workflow_rule_states')));
        self::assertCount(1, $backfill);
        $this->em()->getConnection()->executeStatement($backfill[0]->getStatement());

        $stored = $this->em()->getConnection()->fetchAllKeyValue('SELECT rule_id, work_request_id FROM workflow_rule_states WHERE card_id = ?', [(string) $cardId]);
        self::assertSame((string) $live->id, $stored['live']);
        self::assertNull($stored['settled']);
    }

    private function state(Card $card, string $ruleId): WorkflowRuleState
    {
        return new WorkflowRuleState($card->id ?? throw new \LogicException('The card is persisted.'), $card->project, $ruleId);
    }

    private function request(Project $project, Card $card, string $ruleId, string $at): WorkRequest
    {
        $request = new WorkRequest($project, WorkSubject::CARD, $card->id ?? throw new \LogicException('The card is persisted.'), $card->number, 'implement', null, $ruleId, new \DateTimeImmutable($at));
        $this->em()->persist($request);

        return $request;
    }
}
