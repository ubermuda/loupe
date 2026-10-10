<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Mcp;

use App\Module\AgentReview\Command\SubmitAgentReviewCommand;
use App\Module\AgentReview\Command\SubmitAgentReviewHandler;
use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\AgentReview\Mcp\AgentReviewSubmitTool;
use App\Module\AgentReview\Repository\AgentReviewRepository;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Mcp\AgentRunCause;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Service\CardPullRequests;
use App\Module\Board\Service\PullRequestUrlResolver;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Command\BindWorkflowTemplateHandler;
use App\Module\Workflow\Contract\AgentReviewFailingSeverities;
use App\Module\Workflow\Contract\CardEvaluations;
use App\Tests\Module\AgentReview\AgentReviewScenario;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;

final class AgentReviewSubmitToolTest extends KernelTestCase
{
    use AgentReviewScenario;
    use McpTokenScenario;

    private const string URL = 'https://github.com/acme/widgets/pull/7';

    private const string NO_RUN = 'agent_review_submit takes a review only from a running review worker of this card.';

    private EntityManagerInterface $em;
    private AgentReviewSubmitTool $tool;
    private Project $project;
    private Card $card;
    private ForgePullRequest $pullRequest;
    private Uuid $sessionId;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $tool = self::getContainer()->get(AgentReviewSubmitTool::class);
        self::assertInstanceOf(AgentReviewSubmitTool::class, $tool);
        $this->tool = $tool;

        $this->project = $this->makeProject('agent-review-submit');
        $this->card = $this->card($this->project);
        $this->pullRequest = $this->pullRequest($this->project);
        $this->em->persist(new CardPullRequest($this->card, self::URL, Forge::GitHub, 'Acme/Widgets', 7));
        $this->sessionId = Uuid::v4();
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($this->project);
    }

    public function test_it_stores_a_failing_review_for_an_important_finding(): void
    {
        $run = $this->reviewRun();
        $this->sendSession($this->sessionId);

        $result = $this->submit([$this->finding('important'), $this->finding('nit')]);

        self::assertSame(['conclusion' => 'failure', 'current' => true], ['conclusion' => $result['conclusion'], 'current' => $result['current']]);
        $review = $this->stored($result['reviewId']);
        self::assertSame(str_repeat('a', 40), $review->headSha);
        self::assertSame('Two findings.', $review->summary);
        self::assertTrue($this->pullRequest->id?->equals($review->pullRequest->id));
        self::assertTrue($this->card->id?->equals($review->card->id));
        self::assertTrue($run->id?->equals($review->workerRunId));
        self::assertTrue($run->workRequestId?->equals($review->workRequestId));
        self::assertSame([AgentReviewSeverity::Important, AgentReviewSeverity::Nit], array_map(static fn ($finding) => $finding->severity, $review->findings()));
        self::assertNull($review->postedAt);
    }

    public function test_it_evaluates_every_card_that_links_the_reviewed_pull_request(): void
    {
        $other = $this->card($this->project, 2);
        $this->em->persist(new CardPullRequest($other, self::URL, Forge::GitHub, 'Acme/Widgets', 7));
        $this->em->flush();
        $this->reviewRun();
        $evaluations = new class implements CardEvaluations {
            /** @var list<string|Uuid> */
            public array $cardIds = [];

            public function forCards(array $cardIds): void
            {
                $this->cardIds = [...$this->cardIds, ...$cardIds];
            }

            public function isOn(): bool
            {
                return true;
            }
        };
        $container = self::getContainer();
        $handler = new SubmitAgentReviewHandler(
            $container->get(WorkerRunRepository::class),
            $container->get(PullRequestUrlResolver::class),
            $container->get(CardPullRequests::class),
            $container->get(CardPullRequestRepository::class),
            $container->get(ForgePullRequestRepository::class),
            $container->get(AgentReviewFailingSeverities::class),
            $this->em,
            $evaluations,
            $container->get(Auditor::class),
        );

        $handler(new SubmitAgentReviewCommand($this->card, $this->sessionId, self::URL, str_repeat('a', 40), 'Nothing found.', []));

        $evaluated = array_map(static fn (string|Uuid $id): string => (string) $id, $evaluations->cardIds);
        self::assertContains((string) $this->card->id, $evaluated);
        self::assertContains((string) $other->id, $evaluated);
    }

    public function test_nits_alone_pass_under_the_default_severities(): void
    {
        $this->reviewRun();
        $this->sendSession($this->sessionId);

        self::assertSame('success', $this->submit([$this->finding('nit'), $this->finding('pre-existing')])['conclusion']);
    }

    public function test_a_nit_fails_when_the_workflow_of_the_project_counts_nits_as_failing(): void
    {
        $bind = self::getContainer()->get(BindWorkflowTemplateHandler::class);
        self::assertInstanceOf(BindWorkflowTemplateHandler::class, $bind);
        $binding = $bind(new BindWorkflowTemplateCommand($this->project, 'simple', []));
        $binding->definition = [...$binding->definition, 'agentReviewFailingSeverities' => ['important', 'nit']];
        $this->em->flush();
        $this->reviewRun();
        $this->sendSession($this->sessionId);

        self::assertSame('failure', $this->submit([$this->finding('nit')])['conclusion']);
    }

    public function test_a_review_of_an_older_commit_is_stored_and_not_current(): void
    {
        $this->reviewRun();
        $this->sendSession($this->sessionId);

        $result = $this->submit([], str_repeat('c', 40));

        self::assertFalse($result['current']);
        self::assertSame(str_repeat('c', 40), $this->stored($result['reviewId'])->headSha);
    }

    public function test_a_call_with_no_session_is_refused(): void
    {
        $this->reviewRun();
        $this->sendSession(null);

        $this->assertRefused(self::NO_RUN, fn () => $this->submit([]));
    }

    public function test_a_run_of_another_card_is_refused(): void
    {
        $this->reviewRun(cardId: Uuid::v7());
        $this->sendSession($this->sessionId);

        $this->assertRefused(self::NO_RUN, fn () => $this->submit([]));
    }

    public function test_a_run_of_other_work_is_refused(): void
    {
        $this->reviewRun(workKind: 'implement');
        $this->sendSession($this->sessionId);

        $this->assertRefused(self::NO_RUN, fn () => $this->submit([]));
    }

    public function test_a_closed_run_is_refused(): void
    {
        $this->reviewRun(state: WorkerRunState::Succeeded);
        $this->sendSession($this->sessionId);

        $this->assertRefused(self::NO_RUN, fn () => $this->submit([]));
    }

    public function test_a_pull_request_the_card_does_not_link_is_refused(): void
    {
        $this->reviewRun();
        $this->sendSession($this->sessionId);

        $this->assertRefused('pullRequestUrl must name a pull request that the card links.', fn () => ($this->tool)((string) $this->card->id, 'https://github.com/acme/widgets/pull/8', str_repeat('a', 40), 'Fine.', []));
    }

    public function test_a_linked_pull_request_that_loupe_has_not_read_is_refused(): void
    {
        $this->em->persist(new CardPullRequest($this->card, 'https://github.com/acme/widgets/pull/9', Forge::GitHub, 'acme/widgets', 9));
        $this->reviewRun();
        $this->sendSession($this->sessionId);

        $this->assertRefused('Loupe has not read this pull request', fn () => ($this->tool)((string) $this->card->id, 'https://github.com/acme/widgets/pull/9', str_repeat('a', 40), 'Fine.', []));
    }

    public function test_a_short_sha_is_refused(): void
    {
        $this->reviewRun();
        $this->sendSession($this->sessionId);

        $this->assertRefused('headSha must be the full commit SHA', fn () => $this->submit([], 'abc1234'));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badFindings(): iterable
    {
        yield 'absolute path' => [['path' => '/etc/passwd'], 'findings[0].path'];
        yield 'parent path' => [['path' => 'src/../../x'], 'findings[0].path'];
        yield 'line zero' => [['startLine' => 0], 'findings[0].startLine'];
        yield 'reversed range' => [['startLine' => 5, 'endLine' => 4], 'findings[0].endLine'];
        yield 'unknown severity' => [['severity' => 'blocker'], 'findings[0].severity'];
        yield 'empty title' => [['title' => ' '], 'findings[0].title'];
        yield 'body not a string' => [['body' => 3], 'findings[0].body'];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('badFindings')]
    public function test_a_bad_finding_is_refused(array $override, string $message): void
    {
        $this->reviewRun();
        $this->sendSession($this->sessionId);

        $this->assertRefused($message, fn () => $this->submit([[...$this->finding('nit'), ...$override]]));
    }

    public function test_too_many_findings_are_refused(): void
    {
        $this->reviewRun();
        $this->sendSession($this->sessionId);

        $this->assertRefused('at most 200 findings', fn () => $this->submit(array_fill(0, AgentReviewSubmitTool::MAX_FINDINGS + 1, $this->finding('nit'))));
        $reviews = self::getContainer()->get(AgentReviewRepository::class);
        self::assertInstanceOf(AgentReviewRepository::class, $reviews);
        self::assertSame(0, $reviews->count(['card' => $this->card]));
    }

    /**
     * @param list<array<string, mixed>> $findings
     *
     * @return array{reviewId: string, conclusion: string, current: bool}
     */
    private function submit(array $findings, ?string $headSha = null): array
    {
        return ($this->tool)((string) $this->card->id, self::URL, $headSha ?? str_repeat('a', 40), count($findings) > 0 ? 'Two findings.' : 'Nothing found.', $findings);
    }

    /** @return array<string, mixed> */
    private function finding(string $severity): array
    {
        return ['path' => 'src/Foo.php', 'startLine' => 3, 'endLine' => 5, 'severity' => $severity, 'title' => 'Null read', 'body' => 'The value can be null here.'];
    }

    private function reviewRun(?Uuid $cardId = null, string $workKind = 'review', WorkerRunState $state = WorkerRunState::Running): WorkerRun
    {
        $run = new WorkerRun(
            project: $this->project,
            bridgeId: Uuid::v4(),
            subjectType: WorkSubject::CARD,
            subjectId: $cardId ?? $this->card->id ?? throw new \LogicException('A flushed card has an id.'),
            cardNumber: 1,
            workKind: $workKind,
            state: $state,
            sessionId: $this->sessionId,
            workRequestId: Uuid::v7(),
        );
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    private function sendSession(?Uuid $session): void
    {
        $request = Request::create('/mcp', Request::METHOD_POST);
        if (null !== $session) {
            $request->headers->set(AgentRunCause::SESSION_HEADER, (string) $session);
        }
        $requests = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push($request);
    }

    private function stored(string $reviewId): AgentReview
    {
        $this->em->clear();
        $review = $this->em->find(AgentReview::class, $reviewId);
        self::assertInstanceOf(AgentReview::class, $review);

        return $review;
    }

    private function assertRefused(string $message, \Closure $call): void
    {
        try {
            $call();
            self::fail('The call was not refused.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }
}
