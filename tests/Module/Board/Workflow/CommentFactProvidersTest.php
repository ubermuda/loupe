<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Board\Workflow\FixRunFactProvider;
use App\Module\Board\Workflow\FixRunFacts;
use App\Module\Board\Workflow\StaleApprovalFactProvider;
use App\Module\Board\Workflow\StaleApprovalFacts;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CommentFactProvidersTest extends KernelTestCase
{
    use ActionScenario;

    public function test_the_fix_run_facts_list_the_open_or_recent_fix_runs_with_no_comment_by_id(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('fix-run-facts'), 'in-review');
        $this->pullRequest($card);
        $second = $this->fixRun($card, WorkerRunState::Running);
        $first = $this->fixRun($card, WorkerRunState::Queued);
        $this->fixRun($card, WorkerRunState::Failed, new \DateTimeImmutable('-2 hours'));
        $ids = [(string) $first->id, (string) $second->id];
        sort($ids);

        $provider = $this->service(FixRunFactProvider::class);
        $facts = $provider->build($card->snapshot());

        self::assertEquals(new FixRunFacts($ids), $facts);
        self::assertSame($ids, $provider->fingerprint($facts));
    }

    public function test_the_stale_approval_facts_map_each_pull_request_to_its_head(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('stale-approval-facts'), 'in-review');
        $stale = $this->pullRequest($card, headSha: 'head2');
        $stale->approvalId = 'review-1';
        $stale->coveredSha = 'head1';
        $stale->uncoveredSha = 'head2';
        $covered = $this->pullRequest($card, headSha: 'head3');
        $covered->approvalId = 'review-2';
        $covered->coveredSha = 'head3';
        $this->em()->flush();

        $provider = $this->service(StaleApprovalFactProvider::class);
        $facts = $provider->build($card->snapshot());

        self::assertEquals(new StaleApprovalFacts([(string) $stale->id => 'head2']), $facts);
        self::assertSame([(string) $stale->id => 'head2'], $provider->fingerprint($facts));
    }

    private function fixRun(Card $card, WorkerRunState $state, \DateTimeImmutable $receivedAt = new \DateTimeImmutable()): WorkerRun
    {
        $run = new WorkerRun(
            project: $card->project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('A flushed card has an id.'),
            cardNumber: $card->number,
            workKind: 'fix',
            state: $state,
            receivedAt: $receivedAt,
        );
        $this->em()->persist($run);
        $this->em()->flush();

        return $run;
    }
}
