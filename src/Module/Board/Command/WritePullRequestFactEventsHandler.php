<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Service\FailedCheckNames;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\ForgeEventType;
use App\Module\Forge\PullRequestSnapshot;
use App\Outbox\OutboxWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Turns a change in the state of a pull request into one outbox row per fact
 * for each card that links it, and tells each card whose page shows the change.
 *
 * Forge calls this inside the transaction that stores the new state, so a
 * throw here rolls that state back and the next read diffs again.
 *
 * @phpstan-type Fact array{type: string, fields: array<string, mixed>}
 */
final readonly class WritePullRequestFactEventsHandler
{
    /** The shapes a reader of the outbox accepts. A bad value is left out rather than sent. */
    private const string REPOSITORY_PATTERN = '#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)+$#D';
    private const string URL_PATTERN = '#^https://[A-Za-z0-9._~:/?\#\[\]@!$&()*+,;=%-]+$#D';
    private const string SHA_PATTERN = '#^[0-9a-f]{7,64}$#D';
    private const int MAX_REPOSITORY_LENGTH = 255;
    private const int MAX_URL_LENGTH = 2000;

    public function __construct(
        private CardPullRequestRepository $cardPullRequests,
        private OutboxWriter $outbox,
        private EntityManagerInterface $em,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(WritePullRequestFactEventsCommand $command): void
    {
        $pullRequest = $command->pullRequest;
        $forge = Forge::tryFrom($pullRequest->forge);
        $facts = self::facts($command);
        $displayed = self::displayedChange($command->previous, $command->current);
        if (null === $forge || ([] === $facts && !$displayed)) {
            return;
        }

        $projectId = $pullRequest->project->id ?? throw new \LogicException('A stored pull request has a project id.');
        $links = [];
        foreach ($this->cardPullRequests->findForPullRequest($projectId, $forge, $pullRequest->repository, $pullRequest->number) as $link) {
            // Two spellings of one URL on one card are two links, and the card must hear once.
            $links[(string) $link->card->id] ??= $link;
        }

        foreach ($links as $link) {
            foreach ($facts as $fact) {
                $this->write($link, $command->current->headSha, $fact['type'], $fact['fields']);
            }
        }
        $this->em->flush();

        if (!$displayed) {
            return;
        }
        // Inside the transaction of Forge, so `updated` alone: a rollback costs a page one needless refetch.
        foreach ($links as $link) {
            $this->events->dispatch(new CardChanged(
                $projectId,
                $link->card->id ?? throw new \LogicException('A linked card has an id.'),
                CardChanged::UPDATED,
                false,
            ));
        }
    }

    /** Whether the card page or the tile shows something new. A new head or base alone shows nothing, unless it makes the approval outdated. */
    private static function displayedChange(PullRequestSnapshot $previous, PullRequestSnapshot $current): bool
    {
        return $previous->state !== $current->state
            || $previous->draft !== $current->draft
            || $previous->checks !== $current->checks
            || $previous->failedChecks !== $current->failedChecks
            || $previous->mergeability !== $current->mergeability
            || $previous->review !== $current->review
            || $previous->readyToMerge !== $current->readyToMerge
            || self::approvalIsStale($previous) !== self::approvalIsStale($current);
    }

    /** The rule of ForgePullRequest::approvalIsStale(), read from a snapshot. */
    private static function approvalIsStale(PullRequestSnapshot $snapshot): bool
    {
        return null !== $snapshot->approvalId && $snapshot->coveredSha !== $snapshot->headSha;
    }

    /** @return list<Fact> */
    private static function facts(WritePullRequestFactEventsCommand $command): array
    {
        $previous = $command->previous;
        $current = $command->current;
        $facts = [];

        $verdict = $command->reviewVerdict;
        if (PullRequestReview::Approved === $verdict || PullRequestReview::ChangesRequested === $verdict) {
            $facts[] = ['type' => ForgeEventType::REVIEW_SUBMITTED, 'fields' => ['verdict' => $verdict->value]];
        }

        if ($current->checksConcludedSince($previous)) {
            $failed = PullRequestChecks::Failed === $current->checks;
            $facts[] = ['type' => ForgeEventType::CHECKS_CONCLUDED, 'fields' => [
                'conclusion' => $current->checks->value,
                'failedChecks' => $failed ? FailedCheckNames::clean($current->failedChecks) : [],
            ]];
        }
        if ($previous->mergeability !== $current->mergeability) {
            if (PullRequestMergeability::Conflicting === $current->mergeability) {
                $facts[] = ['type' => ForgeEventType::CONFLICTED, 'fields' => []];
            }
            if (PullRequestMergeability::Behind === $current->mergeability) {
                $facts[] = ['type' => ForgeEventType::BEHIND, 'fields' => []];
            }
        }
        if ($previous->state !== $current->state) {
            if (PullRequestState::Merged === $current->state) {
                $facts[] = ['type' => ForgeEventType::MERGED, 'fields' => []];
            }
            if (PullRequestState::Closed === $current->state) {
                $facts[] = ['type' => ForgeEventType::CLOSED, 'fields' => []];
            }
        }

        return $facts;
    }

    /** @param array<string, mixed> $fields */
    private function write(CardPullRequest $link, ?string $headSha, string $type, array $fields): void
    {
        $card = $link->card;
        $project = $card->project;
        $cardId = (string) $card->id;
        $headSha = null === $headSha ? null : mb_strtolower($headSha);

        // Identifiers only, and the actor is `system`: the fact came from the forge and nobody here judged the card.
        $payload = [
            'type' => $type,
            'subject' => ['type' => 'card', 'id' => $cardId],
            'projectId' => (string) $project->id,
            'cardId' => $cardId,
            'cardNumber' => $card->number,
            'forge' => $link->forge->value,
        ];
        if (null !== $link->repository && \strlen($link->repository) <= self::MAX_REPOSITORY_LENGTH && 1 === preg_match(self::REPOSITORY_PATTERN, $link->repository)) {
            $payload['repository'] = $link->repository;
        }
        $payload['pullRequestNumber'] = $link->number;
        $host = parse_url($link->url, \PHP_URL_HOST);
        if (\strlen($link->url) <= self::MAX_URL_LENGTH && 1 === preg_match(self::URL_PATTERN, $link->url) && \is_string($host) && '' !== $host) {
            $payload['pullRequestUrl'] = $link->url;
        }
        if (null !== $headSha && 1 === preg_match(self::SHA_PATTERN, $headSha)) {
            $payload['headSha'] = $headSha;
        }
        $payload['actor'] = CardReporter::System->value;

        $this->outbox->write($project, $type, $payload + $fields);
    }
}
