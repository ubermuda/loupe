<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Action;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Bridge\Command\OpenWorkRequestHandler;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\WorkRequestAnnouncer;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlers;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Action\WorkRequestOpener;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Expression\AllOf;
use App\Module\Workflow\Service\CardPullRequests;
use App\Module\Workflow\Template\ActionCall;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\Rule;
use App\Outbox\OutboxWriter;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Component\Clock\MockClock;
use Ubermuda\AuditBundle\Auditor;

/** Cards, pull requests and rules for the action tests that run against the database. */
trait ActionScenario
{
    use WorkflowProjects;

    private int $cardNumber = 0;

    private int $pullRequestNumber = 0;

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /** Built by hand, so a request takes the fixed time of the test. */
    private function openWorkRequestHandler(): OpenWorkRequestHandler
    {
        return new OpenWorkRequestHandler(
            $this->service(WorkRequestRepository::class),
            $this->service(OutboxWriter::class),
            $this->em(),
            new MockClock('2026-10-02 12:00:00'),
            $this->service(Auditor::class),
            $this->service(WorkRequestAnnouncer::class),
            $this->service(WorkerRunRepository::class),
            new WorkSubjectHandlers([]),
        );
    }

    private function opener(): WorkRequestOpener
    {
        return new WorkRequestOpener($this->openWorkRequestHandler(), $this->service(CardPullRequests::class), $this->service(CardPullRequestRepository::class));
    }

    private function card(Project $project, string $column): Card
    {
        $card = new Card($project, $this->column($project, $column), 'Card', '', ++$this->cardNumber);
        $this->em()->persist($card);
        $this->em()->flush();

        return $card;
    }

    /** @param array<string, int|string> $params */
    private function rule(ActionType $type, array $params, string $id = 'test-rule'): Rule
    {
        return new Rule($id, null, new AllOf([]), new ActionCall($type, $params));
    }

    private function state(Card $card, string $ruleId = 'test-rule'): WorkflowRuleState
    {
        return new WorkflowRuleState($card, $card->project, $ruleId);
    }

    private function pullRequest(
        Card $card,
        PullRequestState $state = PullRequestState::Open,
        ?string $base = 'main',
        ?string $head = null,
        ?string $headSha = 'head1',
        ?string $url = null,
    ): ForgePullRequest {
        $number = ++$this->pullRequestNumber;
        $this->em()->persist(new CardPullRequest($card, $url ?? 'https://github.com/acme/widgets/pull/'.$number, Forge::GitHub, 'acme/widgets', $number));
        $pullRequest = new ForgePullRequest($card->project, 'github', 'acme/widgets', $number);
        $pullRequest->state = $state;
        $pullRequest->baseBranch = $base;
        $pullRequest->headBranch = $head ?? 'branch-'.$number;
        $pullRequest->headSha = $headSha;
        $pullRequest->defaultBranch = 'main';
        $this->em()->persist($pullRequest);
        $this->em()->flush();

        return $pullRequest;
    }

    /** @return list<array<mixed>> the detail of each fix-requested event of the card */
    private function fixEvents(Card $card): array
    {
        $rows = $this->service(CardEventRepository::class)->findKindsOfCards($card->project, [$card->id ?? throw new \LogicException('A flushed card has an id.')], [CardEventKind::FixRequested]);

        return array_values(array_map(static fn (array $row): array => $row['detail'], array_filter($rows, static fn (array $row): bool => CardEventKind::FixRequested === $row['kind'])));
    }
}
