<?php

declare(strict_types=1);

namespace App\Tests\Module\Board;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Entity\Tag;
use App\Module\Workflow\Contract\PauseKind;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Component\Uid\Uuid;

/** Gives a card each fact that makes a state, on a project that is bound to the Lifecycle template. */
trait CardStateFixtures
{
    use WorkflowProjects;

    private int $nextCardNumber = 1;

    private int $nextInboxNumber = 1;

    /** @var array<string, Tag> */
    private array $stateTags = [];

    private function stateProject(string $name): Project
    {
        $project = $this->workflowProject($name);
        $this->bindLifecycle($project);
        $this->nextCardNumber = 1;

        return $project;
    }

    private function stateCard(Project $project, string $column = 'in-progress'): Card
    {
        $number = $this->nextCardNumber++;
        $card = new Card(project: $project, column: $this->column($project, $column), title: 'Card '.$number, body: '', number: $number, type: 'feature', position: $number);
        if ($card->column->terminal) {
            $card->completedAt = new \DateTimeImmutable();
        }
        $this->em()->persist($card);
        $this->em()->flush();

        return $card;
    }

    private function pauseCard(Card $card, string $at = '2026-10-02 09:00:00'): CardPause
    {
        $pause = new CardPause($card, $card->project, 'review-failed', 'fix-rule', PauseKind::Retries, new \DateTimeImmutable($at));
        $this->em()->persist($pause);
        $this->em()->flush();

        return $pause;
    }

    private function requestWork(Card $card, string $kind = 'implement', string $at = '2026-10-02 09:30:00'): WorkRequest
    {
        $request = new WorkRequest($card->project, WorkSubject::CARD, $card->id ?? throw new \LogicException('A flushed card has an id.'), $card->number, $kind, null, $kind.'-rule', new \DateTimeImmutable($at));
        $this->em()->persist($request);
        $this->em()->flush();

        return $request;
    }

    private function openRun(Card $card, string $workKind = 'implement', string $at = '2026-10-02 09:45:00'): WorkerRun
    {
        $run = new WorkerRun(
            project: $card->project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('A flushed card has an id.'),
            cardNumber: $card->number,
            workKind: $workKind,
            state: WorkerRunState::Running,
            startedAt: new \DateTimeImmutable($at),
            receivedAt: new \DateTimeImmutable($at),
        );
        $this->em()->persist($run);
        $this->em()->flush();

        return $run;
    }

    /** @param array<string, mixed> $read the named arguments of the PullRequestSnapshot the forge read */
    private function linkPullRequest(Card $card, array $read, ?\DateTimeImmutable $readAt = null): ForgePullRequest
    {
        $number = 100 + $card->number;
        $row = new ForgePullRequest($card->project, 'github', 'acme/app', $number);
        $row->apply(new PullRequestSnapshot(...[...['headSha' => 'head1', 'baseBranch' => 'main', 'defaultBranch' => 'main'], ...$read]));
        $readAt ??= new \DateTimeImmutable('2026-10-02 08:00:00');
        $row->coveredSha = $read['coveredSha'] ?? null;
        $row->refreshedAt = $readAt;
        $row->settleReadyToMerge($read['readyToMerge'] ?? false, $readAt);
        $row->settleStartTimes($readAt);
        $this->em()->persist($row);
        $link = new CardPullRequest($card, 'https://github.com/acme/app/pull/'.$number, Forge::GitHub, 'acme/app', $number);
        $card->pullRequests->add($link);
        $this->em()->persist($link);
        $this->em()->flush();

        return $row;
    }

    private function askOwner(Card $card, InboxItemKind $kind = InboxItemKind::Question, string $at = '2026-10-02 09:15:00'): InboxItem
    {
        $item = new InboxItem($card->project, $this->nextInboxNumber++, $kind, 'Which option?', true, createdAt: new \DateTimeImmutable($at));
        $item->cards->add($link = new InboxItemCard($item, $card));
        $this->em()->persist($item);
        $this->em()->persist($link);
        $this->em()->flush();

        return $item;
    }

    private function reviewDocument(Card $card, string $tagName = 'tech-design', string $at = '2026-10-02 09:20:00'): Document
    {
        $owner = $card->project->owner;
        $document = new Document($owner, $card->project, 'Design of '.$card->number);
        $document->status = DocumentStatus::InReview;
        $key = $card->project->id.'/'.$tagName;
        $tag = $this->stateTags[$key] ??= new Tag($card->project, $tagName);
        $this->em()->persist($tag);
        $document->tags->add($tag);
        $this->em()->persist($document);
        $version = new DocumentVersion($document, 1, '# Design', '<h1>Design</h1>', createdAt: new \DateTimeImmutable($at));
        $document->versions->add($version);
        $this->em()->persist($version);
        $this->em()->persist(new CardDocument($card, $document));
        $this->em()->flush();

        return $document;
    }

    /** An open blocker, and the stamp that the engine writes when the blocker alone holds the card. */
    private function holdByBlocker(Card $card, string $at = '2026-10-02 09:10:00'): Card
    {
        $blocker = $this->stateCard($card->project, 'next');
        $this->em()->persist(new CardLink($blocker, $card, CardLinkKind::Blocks));
        $state = $this->em()->getRepository(WorkflowRuleState::class)->findOneBy(['cardId' => $card->id, 'ruleId' => 'tech-design-approved']) ?? new WorkflowRuleState($card->id ?? throw new \LogicException('A persisted card has an id.'), $card->project, 'tech-design-approved');
        $state->heldByBlockerSince = new \DateTimeImmutable($at);
        $this->em()->persist($state);
        $this->em()->flush();

        return $blocker;
    }
}
