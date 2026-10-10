<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardVerdictDeliveryRepository;
use App\Module\Board\Repository\CardVerdictRepository;
use App\Module\Board\Repository\SiteReviewCheckStateRepository;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;

final readonly class SiteReviewFactProvider implements FactProvider
{
    public function __construct(
        private CardRepository $cards,
        private CardVerdictRepository $cardVerdicts,
        private CardVerdictDeliveryRepository $cardVerdictDeliveries,
        private CardPullRequestRepository $cardPullRequests,
        private SiteReviewCheckStateRepository $siteReviewCheckStates,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return SiteReviewFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $snapshot): object
    {
        $card = $this->cards->find($snapshot->id) ?? throw new \LogicException('The card of the facts exists.');

        $checks = $this->wantedChecks($this->cardPullRequests->findOpenGitHubForCard($card));

        return new SiteReviewFacts(
            $this->cardVerdictDeliveries->findPendingIdsForCard($card),
            $checks,
        );
    }

    /**
     * The check each of those open pull requests should carry, beside the one Loupe last posted.
     * It counts the pending notes of every card of the project that links the pull request.
     *
     * @param list<ForgePullRequest> $pullRequests
     *
     * @return array<string, CheckWanted> keyed by forge pull request id
     */
    public function wantedChecks(array $pullRequests): array
    {
        $checks = [];
        foreach ($pullRequests as $pullRequest) {
            if (null === $pullRequest->headSha) {
                continue;
            }
            $notes = $this->carriedPendingNotes($this->linkedCards($pullRequest));
            $noteCount = \count($notes);
            $posted = $this->siteReviewCheckStates->findOneByPullRequest($pullRequest);
            $checks[(string) $pullRequest->id] = new CheckWanted(
                $pullRequest->headSha,
                0 < $noteCount ? CheckWanted::FAILURE : CheckWanted::SUCCESS,
                $noteCount,
                $posted?->headSha,
                $posted?->conclusion,
                $posted?->checkRunId,
                $posted?->noteCount,
                $this->notesDigest($notes),
                $posted?->notesDigest,
                $notes,
            );
        }

        return $checks;
    }

    /**
     * The copies in the verdicts of those cards whose note is still pending, by card number, then verdict time.
     * A note id that shows twice counts once.
     *
     * @param list<Card> $cards
     *
     * @return list<array{id: string, url: string, body: string, anchorCount: int}>
     */
    public function carriedPendingNotes(array $cards): array
    {
        $carried = [];
        foreach ($cards as $card) {
            foreach ($this->cardVerdicts->findForCard($card) as $verdict) {
                foreach ($verdict->notes as $note) {
                    $carried[$note['id']] = $note;
                }
            }
        }
        $pending = array_flip($this->cardVerdicts->findPendingNoteIds(array_keys($carried)));

        return array_values(array_filter($carried, static fn (array $note): bool => isset($pending[$note['id']])));
    }

    /** @return list<Card> the cards of the project that link the pull request, by card number */
    private function linkedCards(ForgePullRequest $pullRequest): array
    {
        $cards = [];
        foreach ($this->cardPullRequests->findForPullRequest($pullRequest->project->id ?? throw new \LogicException('A stored project has an id.'), Forge::GitHub, $pullRequest->repository, $pullRequest->number) as $link) {
            $cards[(string) $link->card->id] = $link->card;
        }

        return array_values($cards);
    }

    /**
     * A digest of the notes in the order the check summary lists them.
     *
     * @param list<array{id: string, url: string, body: string, anchorCount: int}> $notes
     */
    public function notesDigest(array $notes): string
    {
        return hash('sha256', json_encode(array_map(static fn (array $note): array => [$note['id'], $note['url'], $note['body']], $notes), \JSON_THROW_ON_ERROR));
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        if (!$facts instanceof SiteReviewFacts) {
            throw new \LogicException('The provider fingerprints its own facts.');
        }

        return [
            $facts->pendingDeliveryIds,
            array_map(static fn (CheckWanted $check): array => [$check->headSha, $check->wantedConclusion, $check->noteCount, $check->notesDigest], $facts->checks),
        ];
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.board';
    }
}
