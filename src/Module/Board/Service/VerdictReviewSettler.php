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
use App\Routing\PinnedUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        private PinnedUrlGenerator $urls,

        #[Autowire(param: 'kernel.default_locale')]
        private string $locale,
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
        if (!self::isOpen($pullRequest)) {
            return $this->finish($delivery, CardVerdictDeliveryState::Skipped, self::REASON_NOT_OPEN);
        }
        if (!$this->authorIsRead($pullRequest)) {
            return self::AUTHOR_UNREAD;
        }
        // The read can find the pull request closed.
        if (!self::isOpen($pullRequest)) {
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
        $body = $this->body($delivery->verdict->card, $verdict->message, $verdict->notes);

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

    /** @phpstan-impure The author read between two calls can change the state. */
    private static function isOpen(ForgePullRequest $pullRequest): bool
    {
        return PullRequestState::Open === $pullRequest->state;
    }

    private function finish(CardVerdictDelivery $delivery, CardVerdictDeliveryState $state, ?string $reason = null): null
    {
        $delivery->state = $state;
        $delivery->reason = $reason;
        $delivery->settledAt = \DateTimeImmutable::createFromInterface($this->clock->now());

        return null;
    }

    /**
     * The first line names the Loupe site review and links to the notes, and to the preview when a note names one.
     *
     * @param list<array{id: string, url: string, body: string, anchorCount: int}> $notes
     */
    private function body(Card $card, string $message, array $notes): string
    {
        $parts = [$this->sourceLine($card, $notes)];
        if ('' !== trim($message)) {
            $parts[] = trim($message);
        }
        foreach ($notes as $note) {
            $parts[] = \sprintf("%s\n\n%s\n<!-- loupe-note:%s -->", $note['url'], $note['body'], $note['id']);
        }

        return implode("\n\n", $parts);
    }

    /** @param list<array{id: string, url: string, body: string, anchorCount: int}> $notes */
    private function sourceLine(Card $card, array $notes): string
    {
        $cardUrl = $this->urls->generate('app_board_card', [
            'projectId' => (string) $card->project->id,
            'cardId' => (string) $card->id,
            'tab' => 'feedback',
        ]);
        $line = $this->translator->trans('board.verdict.review.source', ['%number%' => $card->number, '%card_url%' => $cardUrl], 'messages', $this->locale);

        $first = $notes[0] ?? null;
        $parts = null === $first ? false : parse_url($first['url']);
        if (\is_array($parts) && isset($parts['scheme'], $parts['host']) && \in_array($parts['scheme'], ['http', 'https'], true)) {
            $preview = str_replace([' ', '(', ')'], ['%20', '%28', '%29'], $first['url']);
            $line .= ' '.$this->translator->trans('board.verdict.review.preview', ['%preview_url%' => $preview], 'messages', $this->locale);
        }

        return $line;
    }
}
