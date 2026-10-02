<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Fact\DocumentFacts;
use App\Module\Workflow\Fact\FactKey;
use App\Module\Workflow\Fact\Facts;
use App\Module\Workflow\Fact\PullRequestFacts;

/** A hash of the fact groups that a rule reads, so a change elsewhere leaves it as it was. The time never counts. */
final readonly class FactFingerprint
{
    /** @param list<FactKey> $keys */
    public function of(Facts $facts, array $keys): string
    {
        $groups = [];
        foreach ($keys as $key) {
            $groups[$key->value] = $this->group($facts, $key);
        }
        ksort($groups);

        return hash('sha256', json_encode($groups, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }

    private function group(Facts $facts, FactKey $key): mixed
    {
        $card = $facts->card;

        return match ($key) {
            FactKey::Slot => $card->slot,
            FactKey::CardType => $card->type,
            FactKey::Blockers => $card->hasOpenBlocker,
            FactKey::Parent => $card->isChild,
            FactKey::Children => [$card->childCount, $card->openChildCount],
            FactKey::Documents => self::sorted(array_map(self::document(...), $card->documents)),
            FactKey::PullRequest => null === $facts->pullRequest ? null : self::pullRequest($facts->pullRequest),
            FactKey::PullRequests => self::sorted(array_map(self::pullRequest(...), $facts->pullRequests)),
            FactKey::WorkRequests => self::sorted($facts->run->activeWorkKinds),
            FactKey::Refusal => $facts->run->lastRefusalCode,
        };
    }

    /** @return array{string, list<string>} */
    private static function document(DocumentFacts $document): array
    {
        return [$document->status, self::sorted($document->tags)];
    }

    /**
     * The close time stays out: a closed pull request takes it from its last read, so a re-read changes it.
     *
     * @return list<mixed>
     */
    private static function pullRequest(PullRequestFacts $pullRequest): array
    {
        return [
            $pullRequest->state->value,
            $pullRequest->draft,
            $pullRequest->checks->value,
            $pullRequest->conflicting,
            $pullRequest->behind,
            $pullRequest->approvalsCoveringHead,
            $pullRequest->changesRequested,
            $pullRequest->baseIsMergeTarget,
            $pullRequest->baseIsEpicBranch,
            $pullRequest->stacked,
            $pullRequest->parentMerged,
        ];
    }

    /**
     * Sorts by the encoded form, so a list of lists sorts the same way as a list of strings.
     *
     * @template T
     *
     * @param list<T> $items
     *
     * @return list<T>
     */
    private static function sorted(array $items): array
    {
        usort($items, static fn (mixed $a, mixed $b): int => json_encode($a, \JSON_THROW_ON_ERROR) <=> json_encode($b, \JSON_THROW_ON_ERROR));

        return $items;
    }
}
