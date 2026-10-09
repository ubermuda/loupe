<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardVerdictDeliveryRepository;
use App\Module\Board\Repository\CardVerdictRepository;
use App\Module\Board\Repository\SiteReviewCheckStateRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Workflow\Contract\FactProvider;
use Symfony\Component\Uid\Uuid;

final readonly class SiteReviewFactProvider implements FactProvider
{
    public function __construct(
        private CardRepository $cards,
        private CardVerdictRepository $cardVerdicts,
        private CardVerdictDeliveryRepository $cardVerdictDeliveries,
        private CardPullRequestRepository $cardPullRequests,
        private SiteReviewCheckStateRepository $siteReviewCheckStates,
        private BoardAutomation $boardAutomation,
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
    public function build(Uuid $cardId): object
    {
        $card = $this->cards->find($cardId) ?? throw new \LogicException('The card of the facts exists.');

        $checks = $this->wantedChecks($card, $this->cardPullRequests->findOpenGitHubForCard($card));

        return new SiteReviewFacts(
            $this->cardVerdictDeliveries->findPendingIdsForCard($card),
            $checks,
            $this->boardAutomation->settingsOf($card->project)->siteReviewCheck,
        );
    }

    /**
     * The check each of those open pull requests should carry, beside the one Loupe last posted.
     *
     * @param list<ForgePullRequest> $pullRequests
     *
     * @return array<string, CheckWanted> keyed by forge pull request id
     */
    public function wantedChecks(Card $card, array $pullRequests): array
    {
        if ([] === $pullRequests) {
            return [];
        }

        $notes = $this->carriedPendingNotes($card);
        $noteCount = \count($notes);
        $digest = $this->notesDigest($notes);
        $wanted = 0 < $noteCount ? CheckWanted::FAILURE : CheckWanted::SUCCESS;
        $checks = [];
        foreach ($pullRequests as $pullRequest) {
            if (null === $pullRequest->headSha) {
                continue;
            }
            $posted = $this->siteReviewCheckStates->findOneByPullRequest($pullRequest);
            $checks[(string) $pullRequest->id] = new CheckWanted(
                $pullRequest->headSha,
                $wanted,
                $noteCount,
                $posted?->headSha,
                $posted?->conclusion,
                $posted?->checkRunId,
                $posted?->noteCount,
                $digest,
                $posted?->notesDigest,
            );
        }

        return $checks;
    }

    /**
     * The copies in the verdicts of the card whose note is still pending.
     *
     * @return list<array{id: string, url: string, body: string, anchorCount: int}>
     */
    public function carriedPendingNotes(Card $card): array
    {
        $carried = [];
        foreach ($this->cardVerdicts->findForCard($card) as $verdict) {
            foreach ($verdict->notes as $note) {
                $carried[$note['id']] = $note;
            }
        }
        $pending = array_flip($this->cardVerdicts->findPendingNoteIds(array_keys($carried)));

        return array_values(array_filter($carried, static fn (array $note): bool => isset($pending[$note['id']])));
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
