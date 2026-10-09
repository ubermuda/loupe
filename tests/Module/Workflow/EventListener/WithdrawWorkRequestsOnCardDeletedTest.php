<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\EventListener;

use App\Module\Board\Event\CardChanged;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\WorkLedger;
use App\Module\Workflow\EventListener\WithdrawWorkRequestsOnCardDeleted;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WithdrawWorkRequestsOnCardDeletedTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_deleted_card_cancels_its_live_requests_and_leaves_the_others(): void
    {
        [$project, $cardId] = $this->scenario();
        $open = $this->request($project, $cardId, 'implement', WorkRequestState::Open);
        $claimed = $this->request($project, $cardId, 'breakdown', WorkRequestState::Claimed);
        $done = $this->request($project, $cardId, 'review', WorkRequestState::Done);
        $other = $this->request($project, Uuid::v7(), 'implement', WorkRequestState::Open);

        $this->listener()(new CardChanged($this->projectId($project), $cardId, CardChanged::DELETED, false));

        $this->em()->clear();
        self::assertSame(
            [WorkRequestState::Cancelled, WorkRequestState::Cancelled, WorkRequestState::Done, WorkRequestState::Open],
            array_map(fn (WorkRequest $request): ?WorkRequestState => $this->em()->find(WorkRequest::class, $request->id)?->state, [$open, $claimed, $done, $other]),
        );
    }

    public function test_an_update_or_a_creation_cancels_nothing(): void
    {
        [$project, $cardId] = $this->scenario();
        $open = $this->request($project, $cardId, 'implement', WorkRequestState::Open);

        $this->listener()(new CardChanged($this->projectId($project), $cardId, CardChanged::UPDATED, false));
        $this->listener()(new CardChanged($this->projectId($project), $cardId, CardChanged::CREATED, false));

        $this->em()->clear();
        self::assertSame(WorkRequestState::Open, $this->em()->find(WorkRequest::class, $open->id)?->state);
    }

    /** @return array{Project, Uuid} */
    private function scenario(): array
    {
        self::bootKernel();

        return [$this->workflowProject('withdraw-on-delete'), Uuid::v7()];
    }

    private function request(Project $project, Uuid $cardId, string $kind, WorkRequestState $state): WorkRequest
    {
        $request = new WorkRequest($project, WorkSubject::CARD, $cardId, 7, $kind, null, 'rule', new \DateTimeImmutable('2026-10-02 12:00:00'));
        $request->state = $state;
        $this->em()->persist($request);
        $this->em()->flush();

        return $request;
    }

    private function listener(): WithdrawWorkRequestsOnCardDeleted
    {
        return new WithdrawWorkRequestsOnCardDeleted($this->service(WorkLedger::class));
    }

    private function projectId(Project $project): Uuid
    {
        return $project->id ?? throw new \LogicException('A flushed project has an id.');
    }
}
