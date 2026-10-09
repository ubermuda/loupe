<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardVerdict;
use App\Module\Board\Entity\CardVerdictDelivery;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardVerdictDeliveryRepository;
use App\Module\Board\Repository\CardVerdictRepository;
use App\Module\Board\Service\CardNoteSnapshot;
use App\Module\Forge\Entity\ForgePullRequest;
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

        $message = trim($command->message);

        // First, so a retry still finds its verdict after the workflow closed the card.
        $saved = $this->cardVerdicts->findBySubmission($card, $command->submissionId);
        if ($saved instanceof CardVerdict) {
            return $this->replay($saved, $command, $message);
        }

        if ($card->column->terminal) {
            throw new DomainErrors(['card' => self::CARD_CLOSED]);
        }
        if ('' === $message && $command->kind->needsMessage()) {
            throw new DomainErrors(['message' => self::MESSAGE_REQUIRED]);
        }

        $replayed = false;
        $picked = $this->pickedPullRequests($card, $command->pullRequestIds);

        $verdict = $this->em->wrapInTransaction(function () use ($command, $card, $message, $picked, &$replayed): CardVerdict {
            // Two sends of one submission queue here, so the second finds the first.
            $this->em->getConnection()->executeStatement('SELECT pg_advisory_xact_lock(hashtext(?))', [(string) $command->submissionId]);
            $raced = $this->cardVerdicts->findBySubmission($card, $command->submissionId);
            if ($raced instanceof CardVerdict) {
                $replayed = true;

                return $raced;
            }

            $notes = $this->notes->pendingOf($card);
            $verdict = new CardVerdict($card, $command->kind, $command->reviewer, $message, $notes, submissionId: $command->submissionId);
            $this->em->persist($verdict);
            foreach ($picked as $pullRequest) {
                $this->em->persist(new CardVerdictDelivery($verdict, $pullRequest));
            }
            $this->cardEvents->record($card, CardEventKind::Verdict, CardReporter::Human, $command->reviewer, [
                'kind' => $command->kind->value,
                'noteCount' => \count($notes),
                'pullRequestCount' => \count($picked),
            ]);
            $this->em->flush();

            return $verdict;
        });

        if ($replayed) {
            return $this->replay($verdict, $command, $message);
        }

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

        if ($this->evaluations->isOn()) {
            $this->evaluations->forCards([$card->id ?? throw new \LogicException('A stored card has an id.')]);
        }

        return $verdict;
    }

    private function replay(CardVerdict $saved, SendCardVerdictCommand $command, string $message): CardVerdict
    {
        if (!$this->sameContent($saved, $command, $message)) {
            throw new DomainErrors(['submissionId' => self::SUBMISSION_REUSED]);
        }

        return $saved;
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
