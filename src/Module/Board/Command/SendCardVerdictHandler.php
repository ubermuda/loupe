<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardVerdict;
use App\Module\Board\Entity\CardVerdictDelivery;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardVerdictDeliveryRepository;
use App\Module\Board\Repository\CardVerdictRepository;
use App\Module\Board\Service\CardNoteSnapshot;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardEvaluations;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Stores a reviewer's verdict with a copy of the pending notes and one delivery
 * for each pull request the reviewer picked. Posting the verdict to the forge
 * belongs to the workflow, which this handler asks to look at the card again.
 */
final readonly class SendCardVerdictHandler
{
    public const string CARD_GONE = 'board.verdict.error.card_gone';

    public const string CARD_CLOSED = 'board.verdict.error.card_closed';

    public const string MESSAGE_REQUIRED = 'board.verdict.error.message_required';

    public const string PULL_REQUEST_NOT_ON_CARD = 'board.verdict.error.pull_request_not_on_card';

    public const string SUBMISSION_REUSED = 'board.verdict.error.submission_reused';

    public function __construct(
        private CardRepository $cards,
        private CardPullRequestRepository $cardPullRequests,
        private CardVerdictRepository $cardVerdicts,
        private CardVerdictDeliveryRepository $cardVerdictDeliveries,
        private CardNoteSnapshot $notes,
        private CardEventRepository $cardEvents,
        private EntityManagerInterface $em,
        private CardEvaluations $evaluations,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(SendCardVerdictCommand $command): CardVerdict
    {
        $card = $this->cards->findOneByIdAndProjectId($command->cardId, (string) $command->project->id);
        if (!$card instanceof Card) {
            throw new DomainErrors(['card' => self::CARD_GONE]);
        }
        if (!$command->submissionId instanceof Uuid) {
            return $this->send($command, $card);
        }

        // Two sends of one submission run one after the other, so the second finds the first.
        $key = hash('xxh3', (string) $command->submissionId);
        $connection = $this->em->getConnection();
        $connection->executeStatement('SELECT pg_advisory_lock(hashtext(?))', [$key]);
        try {
            return $this->send($command, $card);
        } finally {
            $connection->executeStatement('SELECT pg_advisory_unlock(hashtext(?))', [$key]);
        }
    }

    private function send(SendCardVerdictCommand $command, Card $card): CardVerdict
    {
        $message = trim($command->message);

        // First, so a retry still finds its verdict after the workflow closed the card.
        $saved = $command->submissionId instanceof Uuid ? $this->cardVerdicts->findBySubmission($card, $command->submissionId) : null;
        if ($saved instanceof CardVerdict) {
            if (!$this->sameContent($saved, $command, $message)) {
                throw new DomainErrors(['submissionId' => self::SUBMISSION_REUSED]);
            }
            // The first call may have died after the commit, before it asked the engine.
            $this->evaluate($card);

            return $saved;
        }

        if ($card->column->terminal) {
            throw new DomainErrors(['card' => self::CARD_CLOSED]);
        }
        $notes = $this->notes->pendingOf($card);
        if ('' === $message && $command->kind->needsMessage(\count($notes))) {
            throw new DomainErrors(['message' => self::MESSAGE_REQUIRED]);
        }

        $picked = $this->pickedPullRequests($card, $command->pullRequestIds);

        $verdict = $this->em->wrapInTransaction(function () use ($command, $card, $message, $notes, $picked): CardVerdict {
            $verdict = new CardVerdict($card, $command->kind, $command->reviewer, $message, $notes, submissionId: $command->submissionId);
            $this->em->persist($verdict);
            foreach ($picked as $pullRequest) {
                $this->em->persist(new CardVerdictDelivery($verdict, $pullRequest));
            }
            $this->cardEvents->record($card, CardEventKind::Verdict, Actor::Human, $command->reviewer, [
                'kind' => $command->kind->value,
                'noteCount' => \count($notes),
                'pullRequestCount' => \count($picked),
            ]);
            $this->em->flush();

            return $verdict;
        });

        // After the commit, never inside it: the sink drains at kernel.terminate, so a record written in the closure outlives a rollback.
        $this->auditor->record(
            'board.verdict_sent',
            AuditOutcome::Success,
            [
                'verdictId' => (string) $verdict->id,
                'cardId' => (string) $card->id,
                'projectId' => (string) $command->project->id,
                'kind' => $command->kind->value,
                'noteCount' => \count($verdict->notes),
                'pullRequestCount' => \count($picked),
            ],
            new AuditSubject('card', (string) $card->id),
        );
        $this->evaluate($card);

        return $verdict;
    }

    private function evaluate(Card $card): void
    {
        if ($this->evaluations->isOn()) {
            $this->evaluations->forCards($this->cardPullRequests->findCardIdsSharingOpenPullRequests($card));
        }
    }

    private function sameContent(CardVerdict $saved, SendCardVerdictCommand $command, string $message): bool
    {
        if ($saved->kind !== $command->kind || $saved->message !== $message || $saved->reviewer?->id?->toRfc4122() !== $command->reviewer->id?->toRfc4122()) {
            return false;
        }

        $storedIds = array_map(
            static fn (CardVerdictDelivery $delivery): string => (string) $delivery->pullRequest->id,
            $this->cardVerdictDeliveries->findBy(['verdict' => $saved]),
        );
        $sentIds = array_map(
            static fn (string $id): string => Uuid::isValid($id) ? Uuid::fromString($id)->toRfc4122() : $id,
            $command->pullRequestIds,
        );
        sort($storedIds);
        $sentIds = array_values(array_unique($sentIds));
        sort($sentIds);

        return $storedIds === $sentIds;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<ForgePullRequest>
     */
    private function pickedPullRequests(Card $card, array $ids): array
    {
        $open = [];
        foreach ($this->cardPullRequests->findOpenGitHubForCard($card) as $pullRequest) {
            $open[(string) $pullRequest->id] = $pullRequest;
        }

        $picked = [];
        foreach ($ids as $id) {
            $key = Uuid::isValid($id) ? Uuid::fromString($id)->toRfc4122() : $id;
            if (!isset($open[$key])) {
                throw new DomainErrors(['pullRequestIds' => self::PULL_REQUEST_NOT_ON_CARD]);
            }
            $picked[$key] = $open[$key];
        }

        return array_values($picked);
    }
}
