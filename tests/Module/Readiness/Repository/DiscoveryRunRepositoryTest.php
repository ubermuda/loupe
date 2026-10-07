<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Repository;

use App\Module\Board\Entity\CardType;
use App\Module\Readiness\Entity\DiscoveryProposal;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Repository\DiscoveryProposalRepository;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Review\Entity\Document;
use App\Tests\Module\Readiness\DiscoveryScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DiscoveryRunRepositoryTest extends KernelTestCase
{
    use DiscoveryScenario;

    public function test_the_latest_run_of_a_card_is_the_newest_one_of_that_card(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('discovery-latest-card');
        $card = $this->discoveryCard($project);
        $other = $this->discoveryCard($project);
        $this->discoveryRun($card, DiscoveryRunState::Failed, '2026-10-02 10:00:00');
        $latest = $this->discoveryRun($card, DiscoveryRunState::Requested, '2026-10-02 11:00:00');
        $this->discoveryRun($other, DiscoveryRunState::Requested, '2026-10-02 12:00:00');

        self::assertSame($latest, $this->repository()->latestForCard($card->id ?? throw new \LogicException('A flushed card has an id.')));
    }

    public function test_a_card_with_no_run_has_no_latest_run(): void
    {
        self::bootKernel();
        $card = $this->discoveryCard($this->workflowProject('discovery-none'));

        self::assertNull($this->repository()->latestForCard($card->id ?? throw new \LogicException('A flushed card has an id.')));
    }

    public function test_two_runs_of_the_same_second_order_by_id(): void
    {
        self::bootKernel();
        $card = $this->discoveryCard($this->workflowProject('discovery-tie'));
        $this->discoveryRun($card, DiscoveryRunState::Failed);
        $second = $this->discoveryRun($card);

        self::assertSame($second, $this->repository()->latestForCard($card->id ?? throw new \LogicException('A flushed card has an id.')));
    }

    public function test_the_latest_run_of_a_project_spans_its_cards_and_ignores_other_projects(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('discovery-latest-project');
        $this->discoveryRun($this->discoveryCard($project), DiscoveryRunState::Failed, '2026-10-02 10:00:00');
        $latest = $this->discoveryRun($this->discoveryCard($project), DiscoveryRunState::Requested, '2026-10-02 11:00:00');
        $this->discoveryRun($this->discoveryCard($this->workflowProject('discovery-elsewhere')), DiscoveryRunState::Requested, '2026-10-02 12:00:00');

        self::assertSame($latest, $this->repository()->latestForProject($project));
        self::assertNull($this->repository()->latestForProject($this->workflowProject('discovery-empty')));
    }

    public function test_the_requested_runs_of_a_project_leave_out_the_other_states_and_projects(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('discovery-requested');
        $first = $this->discoveryRun($this->discoveryCard($project), DiscoveryRunState::Requested, '2026-10-02 10:00:00');
        $this->discoveryRun($this->discoveryCard($project), DiscoveryRunState::Failed, '2026-10-02 10:30:00');
        $this->discoveryRun($this->discoveryCard($project), DiscoveryRunState::Reported, '2026-10-02 10:40:00');
        $second = $this->discoveryRun($this->discoveryCard($project), DiscoveryRunState::Requested, '2026-10-02 11:00:00');
        $this->discoveryRun($this->discoveryCard($this->workflowProject('discovery-other')));

        self::assertSame([$first, $second], $this->repository()->findRequestedForProject($project));
    }

    public function test_a_deleted_card_takes_its_runs_with_it(): void
    {
        self::bootKernel();
        $card = $this->discoveryCard($this->workflowProject('discovery-card-deleted'));
        $run = $this->discoveryRun($card);
        $runId = $run->id;

        $this->em()->getConnection()->executeStatement('DELETE FROM board_cards WHERE id = :id', ['id' => (string) $card->id]);
        $this->em()->clear();

        self::assertNull($this->em()->find(DiscoveryRun::class, $runId));
    }

    public function test_a_run_fails_only_while_it_is_requested(): void
    {
        $at = new \DateTimeImmutable('2026-10-02 13:00:00');
        self::bootKernel();
        $run = $this->discoveryRun($this->discoveryCard($this->workflowProject('discovery-fail')));

        self::assertTrue($run->fail('no-taker', $at));
        self::assertSame([DiscoveryRunState::Failed, 'no-taker', $at], [$run->state, $run->failureReason, $run->endedAt]);

        self::assertFalse($run->fail('lost', new \DateTimeImmutable('2026-10-02 14:00:00')));
        self::assertSame(['no-taker', $at], [$run->failureReason, $run->endedAt]);

        $reported = $this->discoveryRun($this->discoveryCard($run->project), DiscoveryRunState::Reported);
        self::assertFalse($reported->fail('lost', $at));
        self::assertSame([DiscoveryRunState::Reported, null, null], [$reported->state, $reported->failureReason, $reported->endedAt]);
    }

    public function test_a_run_is_found_by_its_report_document_and_by_no_other(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('discovery-by-report');
        $run = $this->discoveryRun($this->discoveryCard($project), DiscoveryRunState::Reported);
        $other = $this->discoveryRun($this->discoveryCard($project), DiscoveryRunState::Reported);
        $document = new Document($project->owner, $project, 'Report');
        $document->addVersion('x', '<p>x</p>');
        $this->em()->persist($document);
        $run->reportDocument = $document;
        $this->em()->flush();

        self::assertSame($run, $this->repository()->findByReportDocument($document));
        self::assertNotSame($other, $this->repository()->findByReportDocument($document));
        $unlinked = new Document($project->owner, $project, 'Plain');
        $unlinked->addVersion('x', '<p>x</p>');
        $this->em()->persist($unlinked);
        $this->em()->flush();
        self::assertNull($this->repository()->findByReportDocument($unlinked));
    }

    public function test_the_fresh_state_reads_the_row_and_not_the_loaded_run(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('discovery-fresh');
        $run = $this->discoveryRun($this->discoveryCard($project));
        self::assertSame(['state' => DiscoveryRunState::Requested, 'hasReport' => false], $this->repository()->freshStateOf($run));

        $this->em()->getConnection()->executeStatement("UPDATE discovery_runs SET state = 'failed' WHERE id = :id", ['id' => (string) $run->id]);

        self::assertSame(DiscoveryRunState::Requested, $run->state);
        self::assertSame(['state' => DiscoveryRunState::Failed, 'hasReport' => false], $this->repository()->freshStateOf($run));
    }

    public function test_the_proposals_of_a_run_list_in_tick_box_order_with_the_covered_ones_last(): void
    {
        self::bootKernel();
        $run = $this->discoveryRun($this->discoveryCard($this->workflowProject('discovery-proposals')));
        $other = $this->discoveryRun($this->discoveryCard($run->project));
        foreach ([['c', null], ['b', 1], ['a', 0]] as [$key, $position]) {
            $this->em()->persist(new DiscoveryProposal($run, $position, $key, $key, CardType::Docs, ''));
        }
        $this->em()->persist(new DiscoveryProposal($other, 0, 'z', 'z', CardType::Docs, ''));
        $this->em()->flush();
        $repository = self::getContainer()->get(DiscoveryProposalRepository::class);
        self::assertInstanceOf(DiscoveryProposalRepository::class, $repository);

        self::assertSame(['a', 'b', 'c'], array_map(static fn (DiscoveryProposal $proposal): string => $proposal->key, $repository->findForRun($run)));
    }

    private function repository(): DiscoveryRunRepository
    {
        $repository = self::getContainer()->get(DiscoveryRunRepository::class);
        self::assertInstanceOf(DiscoveryRunRepository::class, $repository);

        return $repository;
    }
}
