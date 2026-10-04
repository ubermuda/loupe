<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Entity\Card;
use App\Module\Board\Mcp\AgentRunCause;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

final class AgentRunCauseTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private AgentRunCause $cause;
    private Project $project;
    private Card $card;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $cause = self::getContainer()->get(AgentRunCause::class);
        self::assertInstanceOf(AgentRunCause::class, $cause);
        $this->cause = $cause;

        $this->project = $this->makeProject('agent-run-cause');
        $this->card = new Card($this->project, $this->column($this->project, 'backlog'), 'Ship it', '', 1);
        $this->em->persist($this->card);
        $this->em->flush();
    }

    public function test_no_request_names_no_run(): void
    {
        self::assertNull($this->cause->forCard($this->card));
    }

    public function test_a_request_without_the_header_names_no_run(): void
    {
        $this->pushRequest(null);

        self::assertNull($this->cause->forCard($this->card));
    }

    public function test_a_malformed_header_names_no_run(): void
    {
        $this->pushRequest('not-a-uuid');

        self::assertNull($this->cause->forCard($this->card));
    }

    public function test_an_unknown_session_names_no_run(): void
    {
        $this->workerRun(Uuid::v4(), 'implement', new \DateTimeImmutable('-1 hour'));
        $this->pushRequest((string) Uuid::v4());

        self::assertNull($this->cause->forCard($this->card));
    }

    public function test_a_session_of_another_project_names_no_run(): void
    {
        $sessionId = Uuid::v4();
        $this->workerRun($sessionId, 'implement', new \DateTimeImmutable('-1 hour'), $this->makeProject('agent-run-cause-other'));
        $this->pushRequest((string) $sessionId);

        self::assertNull($this->cause->forCard($this->card));
    }

    public function test_a_known_session_names_its_newest_run(): void
    {
        $sessionId = Uuid::v4();
        $this->workerRun($sessionId, 'implement', new \DateTimeImmutable('-2 hours'));
        $resumed = $this->workerRun($sessionId, 'fix-round', new \DateTimeImmutable('-1 hour'));
        $this->pushRequest((string) $sessionId);

        $cause = $this->cause->forCard($this->card);

        self::assertNotNull($cause);
        self::assertSame(['type' => 'run', 'run' => (string) $resumed->id, 'kind' => 'fix-round'], $cause->detail());
    }

    public function test_a_run_on_the_moved_card_beats_a_newer_run_on_another_card(): void
    {
        $sessionId = Uuid::v4();
        $onCard = $this->workerRun($sessionId, 'implement', new \DateTimeImmutable('-2 hours'), cardId: $this->card->id, state: WorkerRunState::Succeeded);
        $this->workerRun($sessionId, 'product-design', new \DateTimeImmutable('-1 hour'), state: WorkerRunState::Running, kind: WorkerRunKind::Interactive);
        $this->pushRequest((string) $sessionId);

        self::assertSame(['type' => 'run', 'run' => (string) $onCard->id, 'kind' => 'implement'], $this->cause->forCard($this->card)?->detail());
    }

    public function test_with_no_run_on_the_moved_card_an_open_run_beats_a_newer_closed_one(): void
    {
        $sessionId = Uuid::v4();
        $open = $this->workerRun($sessionId, 'implement', new \DateTimeImmutable('-2 hours'), state: WorkerRunState::Running);
        $this->workerRun($sessionId, 'fix-round', new \DateTimeImmutable('-1 hour'), state: WorkerRunState::Succeeded);
        $this->pushRequest((string) $sessionId);

        self::assertSame(['type' => 'run', 'run' => (string) $open->id, 'kind' => 'implement'], $this->cause->forCard($this->card)?->detail());
    }

    private function pushRequest(?string $session): void
    {
        $request = Request::create('/mcp', Request::METHOD_POST);
        if (null !== $session) {
            $request->headers->set(AgentRunCause::SESSION_HEADER, $session);
        }

        $requests = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push($request);
    }

    private function workerRun(
        Uuid $sessionId,
        string $rule,
        \DateTimeImmutable $receivedAt,
        ?Project $project = null,
        ?Uuid $cardId = null,
        WorkerRunState $state = WorkerRunState::Succeeded,
        WorkerRunKind $kind = WorkerRunKind::Worker,
    ): WorkerRun {
        $run = new WorkerRun(
            project: $project ?? $this->project,
            bridgeId: Uuid::v4(),
            cardId: $cardId ?? Uuid::v4(),
            cardNumber: 1,
            workKind: $rule,
            state: $state,
            sessionId: $sessionId,
            receivedAt: $receivedAt,
            kind: $kind,
        );
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }
}
