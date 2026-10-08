<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Entity\CardSourceKind;
use App\Module\Board\Mcp\AgentRunCause;
use App\Module\Board\Mcp\AgentRunSource;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

final class AgentRunSourceTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private AgentRunSource $source;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $source = self::getContainer()->get(AgentRunSource::class);
        self::assertInstanceOf(AgentRunSource::class, $source);
        $this->source = $source;

        $this->project = $this->makeProject('agent-run-source');
    }

    public function test_no_request_names_no_run(): void
    {
        self::assertNull($this->source->forProject($this->project));
    }

    public function test_a_request_without_the_header_names_no_run(): void
    {
        $this->pushRequest(null);

        self::assertNull($this->source->forProject($this->project));
    }

    public function test_a_malformed_header_names_no_run(): void
    {
        $this->pushRequest('not-a-uuid');

        self::assertNull($this->source->forProject($this->project));
    }

    public function test_an_unknown_session_names_no_run(): void
    {
        $this->workerRun(Uuid::v4(), new \DateTimeImmutable('-1 hour'));
        $this->pushRequest((string) Uuid::v4());

        self::assertNull($this->source->forProject($this->project));
    }

    public function test_a_session_of_another_project_names_no_run(): void
    {
        $sessionId = Uuid::v4();
        $this->workerRun($sessionId, new \DateTimeImmutable('-1 hour'), $this->makeProject('agent-run-source-other'));
        $this->pushRequest((string) $sessionId);

        self::assertNull($this->source->forProject($this->project));
    }

    public function test_an_interactive_session_is_no_worker_run(): void
    {
        $sessionId = Uuid::v4();
        $this->workerRun($sessionId, new \DateTimeImmutable('-1 hour'), kind: WorkerRunKind::Interactive);
        $this->pushRequest((string) $sessionId);

        self::assertNull($this->source->forProject($this->project));
    }

    public function test_a_worker_session_names_its_run_and_the_card_of_the_run(): void
    {
        $sessionId = Uuid::v4();
        $cardId = Uuid::v4();
        $run = $this->workerRun($sessionId, new \DateTimeImmutable('-1 hour'), cardId: $cardId);
        $this->pushRequest((string) $sessionId);

        $source = $this->source->forProject($this->project);

        self::assertNotNull($source);
        self::assertSame(CardSourceKind::Run, $source->kind);
        self::assertEquals($run->id, $source->runId);
        self::assertEquals($cardId, $source->runCardId);
    }

    public function test_an_open_run_beats_a_newer_closed_one(): void
    {
        $sessionId = Uuid::v4();
        $open = $this->workerRun($sessionId, new \DateTimeImmutable('-2 hours'), state: WorkerRunState::Running);
        $this->workerRun($sessionId, new \DateTimeImmutable('-1 hour'), state: WorkerRunState::Succeeded);
        $this->pushRequest((string) $sessionId);

        self::assertEquals($open->id, $this->source->forProject($this->project)?->runId);
    }

    public function test_with_no_open_run_the_newest_wins(): void
    {
        $sessionId = Uuid::v4();
        $this->workerRun($sessionId, new \DateTimeImmutable('-2 hours'));
        $newest = $this->workerRun($sessionId, new \DateTimeImmutable('-1 hour'));
        $this->pushRequest((string) $sessionId);

        self::assertEquals($newest->id, $this->source->forProject($this->project)?->runId);
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
        \DateTimeImmutable $receivedAt,
        ?Project $project = null,
        ?Uuid $cardId = null,
        WorkerRunState $state = WorkerRunState::Succeeded,
        WorkerRunKind $kind = WorkerRunKind::Worker,
    ): WorkerRun {
        $run = new WorkerRun(
            project: $project ?? $this->project,
            bridgeId: Uuid::v4(),
            subjectType: WorkSubject::CARD,
            subjectId: $cardId ?? Uuid::v4(),
            cardNumber: 1,
            workKind: 'implement',
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
