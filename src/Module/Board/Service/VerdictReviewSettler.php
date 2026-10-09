<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardVerdictDelivery;
use App\Module\Board\Entity\CardVerdictDeliveryState;
use App\Module\Board\Repository\CardVerdictDeliveryRepository;
use App\Module\Forge\Command\ReadPullRequestStateCommand;
use App\Module\Forge\Command\ReadPullRequestStateHandler;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Service\PullRequestReviewFailed;
use App\Module\Forge\Service\PullRequestReviewKind;
use App\Module\Forge\Service\PullRequestReviewPosters;
use App\Module\Forge\Service\PullRequestUnreadable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Posts the reviews that the verdicts of a card ask for, and settles every pending delivery. */
final readonly class VerdictReviewSettler
{
    public const string REASON_NOT_OPEN = 'not-open';

    public const string REASON_NO_POSTER = 'no-poster';

    public const string AUTHOR_UNREAD = 'author-unread';

    public function __construct(
        private CardVerdictDeliveryRepository $cardVerdictDeliveries,
        private BoardAutomation $boardAutomation,
        private PullRequestReviewPosters $posters,
        private ReviewerForgeAccount $forgeAccount,
        private ReadPullRequestStateHandler $readPullRequestState,
        private TranslatorInterface $translator,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Settles each pending delivery of the card, so a later verdict forms a new edge. A transient failure leaves its
     * delivery pending.
     *
     * The result holds the cause of the first transient failure, and whether a delivery settled.
     */
    public function settle(Card $card): SiteReviewWriteResult
    {
        $optedIn = $this->boardAutomation->settingsOf($card->project)->postWidgetReviews;
        $transient = null;
        $changed = false;
        foreach ($this->cardVerdictDeliveries->findPendingForCard($card) as $delivery) {
            $cause = $this->settleOne($delivery, $optedIn);
            $transient ??= $cause;
            $changed = $changed || null === $cause;
            $this->em->flush();
        }

        return new SiteReviewWriteResult($transient, $changed);
    }

    private function settleOne(CardVerdictDelivery $delivery, bool $optedIn): ?string
    {
        if (!$optedIn) {
            return $this->finish($delivery, CardVerdictDeliveryState::Skipped);
        }
        $pullRequest = $delivery->pullRequest;
        if (PullRequestState::Open !== $pullRequest->state) {
            return $this->finish($delivery, CardVerdictDeliveryState::Skipped, self::REASON_NOT_OPEN);
        }
        if (!$this->authorIsRead($pullRequest)) {
            return self::AUTHOR_UNREAD;
        }
        // The read can find the pull request closed.
        if (PullRequestState::Open !== $pullRequest->state) {
            return $this->finish($delivery, CardVerdictDeliveryState::Skipped, self::REASON_NOT_OPEN);
        }

        $verdict = $delivery->verdict;
        $reviewer = $verdict->reviewer;
        if (null === $reviewer || \in_array($this->forgeAccount->stateOf($reviewer), ['none', 'expired'], true)) {
            return $this->finish($delivery, CardVerdictDeliveryState::Refused, CardVerdictDelivery::REASON_CONNECTION_EXPIRED);
        }
        $poster = $this->posters->for($pullRequest->forge);
        if (null === $poster) {
            return $this->finish($delivery, CardVerdictDeliveryState::Refused, self::REASON_NO_POSTER);
        }

        $reviewerForgeId = $this->forgeAccount->forgeUserIdOf($reviewer);
        $own = null !== $reviewerForgeId && $reviewerForgeId === $pullRequest->authorId;
        $kind = $own ? PullRequestReviewKind::Comment : PullRequestReviewKind::from($verdict->kind->value);
        $body = $this->body($verdict->message, $verdict->notes);
        if ('' === $body && PullRequestReviewKind::Approve !== $kind) {
            $body = $this->translator->trans('board.verdict.review.fallback_body');
        }

        try {
            $url = $poster->post($pullRequest, $kind, $body, (string) $reviewer->id);
        } catch (PullRequestReviewFailed $e) {
            if (\in_array($e->cause, ['not_connected', 'connection_expired'], true)) {
                return $this->finish($delivery, CardVerdictDeliveryState::Refused, CardVerdictDelivery::REASON_CONNECTION_EXPIRED);
            }
            if ($e->permanent) {
                return $this->finish($delivery, CardVerdictDeliveryState::Refused, substr($e->cause, 0, CardVerdictDelivery::MAX_REASON_LENGTH));
            }

            return $e->cause;
        }

        $delivery->reviewUrl = null === $url ? null : substr($url, 0, CardVerdictDelivery::MAX_REVIEW_URL_LENGTH);

        return $this->finish($delivery, $own ? CardVerdictDeliveryState::Commented : CardVerdictDeliveryState::Posted);
    }

    /** A row stored before the author columns existed has no author, and a review of one's own pull request is refused for good. */
    private function authorIsRead(ForgePullRequest $pullRequest): bool
    {
        if (!$pullRequest->authorRead) {
            try {
                ($this->readPullRequestState)(new ReadPullRequestStateCommand((string) $pullRequest->id, $this->clock->now()));
            } catch (PullRequestUnreadable) {
                return false;
            }
        }

        return $pullRequest->authorRead;
    }

    private function finish(CardVerdictDelivery $delivery, CardVerdictDeliveryState $state, ?string $reason = null): null
    {
        $delivery->state = $state;
        $delivery->reason = $reason;
        $delivery->settledAt = \DateTimeImmutable::createFromInterface($this->clock->now());

        return null;
    }

    /** @param list<array{id: string, url: string, body: string, anchorCount: int}> $notes */
    private function body(string $message, array $notes): string
    {
        $parts = '' === trim($message) ? [] : [trim($message)];
        foreach ($notes as $note) {
            $parts[] = \sprintf("%s\n\n%s\n<!-- loupe-note:%s -->", $note['url'], $note['body'], $note['id']);
        }

        return implode("\n\n", $parts);
    }
}
