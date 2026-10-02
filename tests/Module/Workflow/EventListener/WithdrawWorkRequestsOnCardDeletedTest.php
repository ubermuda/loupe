<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\EventListener;

use App\Module\Board\Event\CardChanged;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Engine\EngineSwitch;
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

        $this->listener(true)(new CardChanged($this->projectId($project), $cardId, CardChanged::DELETED, false));

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

        $this->listener(true)(new CardChanged($this->projectId($project), $cardId, CardChanged::UPDATED, false));
        $this->listener(true)(new CardChanged($this->projectId($project), $cardId, CardChanged::CREATED, false));

        $this->em()->clear();
        self::assertSame(WorkRequestState::Open, $this->em()->find(WorkRequest::class, $open->id)?->state);
    }

    public function test_the_switch_off_cancels_nothing(): void
    {
        [$project, $cardId] = $this->scenario();
        $open = $this->request($project, $cardId, 'implement', WorkRequestState::Open);

        $this->listener(false)(new CardChanged($this->projectId($project), $cardId, CardChanged::DELETED, false));

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
        $request = new WorkRequest($project, $cardId, 7, $kind, null, 'rule', new \DateTimeImmutable('2026-10-02 12:00:00'));
        $request->state = $state;
        $this->em()->persist($request);
        $this->em()->flush();

        return $request;
    }

    private function listener(bool $on): WithdrawWorkRequestsOnCardDeleted
    {
        return new WithdrawWorkRequestsOnCardDeleted(new EngineSwitch($on), $this->service(WorkRequestRepository::class), $this->service(WithdrawWorkRequestHandler::class));
    }

    private function projectId(Project $project): Uuid
    {
        return $project->id ?? throw new \LogicException('A flushed project has an id.');
    }
}
