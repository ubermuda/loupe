<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Workflow\Fact\ChecksState;
use App\Module\Workflow\Fact\DocumentFacts;
use App\Module\Workflow\Fact\FactKey;
use App\Module\Workflow\Fact\Facts;
use App\Module\Workflow\Fact\PullRequestFacts;
use App\Module\Workflow\Fact\PullRequestState;
use App\Module\Workflow\Fact\RunFacts;
use App\Module\Workflow\Service\FactFingerprint;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FactFingerprintTest extends TestCase
{
    public function test_the_same_facts_give_the_same_sha256(): void
    {
        $keys = FactKey::cases();

        $hash = new FactFingerprint()->of($this->facts(), $keys);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        self::assertSame($hash, new FactFingerprint()->of($this->facts(), $keys));
    }

    public function test_the_time_and_the_order_of_lists_do_not_count(): void
    {
        $keys = FactKey::cases();
        $reordered = FactsMother::facts(
            card: FactsMother::card(slot: 'implementation', documents: [
                new DocumentFacts(['tech', 'design'], 'in_review'),
                new DocumentFacts(['product'], 'approved'),
            ]),
            pullRequest: FactsMother::pullRequest(checks: ChecksState::Passed),
            pullRequests: [FactsMother::pullRequest(state: PullRequestState::Closed), FactsMother::pullRequest(checks: ChecksState::Passed)],
            run: FactsMother::run(['review', 'implement'], 'no-capacity'),
            now: new \DateTimeImmutable('2030-01-01'),
        );

        self::assertSame(new FactFingerprint()->of($this->facts(), $keys), new FactFingerprint()->of($reordered, $keys));
    }

    /** @return iterable<string, array{FactKey, Facts}> */
    public static function changes(): iterable
    {
        yield 'slot' => [FactKey::Slot, FactsMother::facts(card: FactsMother::card(slot: 'in-review', documents: self::documents()), pullRequest: self::primary(), pullRequests: self::all(), run: self::workRun())];
        yield 'type' => [FactKey::CardType, FactsMother::facts(card: FactsMother::card(slot: 'implementation', type: 'bug', documents: self::documents()), pullRequest: self::primary(), pullRequests: self::all(), run: self::workRun())];
        yield 'blockers' => [FactKey::Blockers, FactsMother::facts(card: FactsMother::card(slot: 'implementation', hasOpenBlocker: true, documents: self::documents()), pullRequest: self::primary(), pullRequests: self::all(), run: self::workRun())];
        yield 'parent' => [FactKey::Parent, FactsMother::facts(card: FactsMother::card(slot: 'implementation', isChild: true, documents: self::documents()), pullRequest: self::primary(), pullRequests: self::all(), run: self::workRun())];
        yield 'children' => [FactKey::Children, FactsMother::facts(card: FactsMother::card(slot: 'implementation', childCount: 2, openChildCount: 1, documents: self::documents()), pullRequest: self::primary(), pullRequests: self::all(), run: self::workRun())];
        yield 'documents' => [FactKey::Documents, FactsMother::facts(card: FactsMother::card(slot: 'implementation', documents: [new DocumentFacts(['product'], 'approved')]), pullRequest: self::primary(), pullRequests: self::all(), run: self::workRun())];
        yield 'pull request' => [FactKey::PullRequest, FactsMother::facts(card: FactsMother::card(slot: 'implementation', documents: self::documents()), pullRequest: FactsMother::pullRequest(checks: ChecksState::Failed), pullRequests: self::all(), run: self::workRun())];
        yield 'pull requests' => [FactKey::PullRequests, FactsMother::facts(card: FactsMother::card(slot: 'implementation', documents: self::documents()), pullRequest: self::primary(), pullRequests: [self::primary()], run: self::workRun())];
        yield 'work requests' => [FactKey::WorkRequests, FactsMother::facts(card: FactsMother::card(slot: 'implementation', documents: self::documents()), pullRequest: self::primary(), pullRequests: self::all(), run: FactsMother::run(['implement'], 'no-capacity'))];
        yield 'refusal' => [FactKey::Refusal, FactsMother::facts(card: FactsMother::card(slot: 'implementation', documents: self::documents()), pullRequest: self::primary(), pullRequests: self::all(), run: FactsMother::run(['implement', 'review'], 'no-bridge'))];
    }

    #[DataProvider('changes')]
    public function test_a_change_counts_only_when_the_keys_read_its_group(FactKey $changed, Facts $facts): void
    {
        $fingerprint = new FactFingerprint();
        $others = array_values(array_filter(FactKey::cases(), static fn (FactKey $key): bool => $key !== $changed));

        self::assertNotSame($fingerprint->of($this->facts(), [$changed]), $fingerprint->of($facts, [$changed]));
        self::assertNotSame($fingerprint->of($this->facts(), FactKey::cases()), $fingerprint->of($facts, FactKey::cases()));
        self::assertSame($fingerprint->of($this->facts(), $others), $fingerprint->of($facts, $others));
    }

    public function test_the_key_order_and_a_repeated_key_do_not_count(): void
    {
        $fingerprint = new FactFingerprint();

        self::assertSame(
            $fingerprint->of($this->facts(), [FactKey::Slot, FactKey::Refusal]),
            $fingerprint->of($this->facts(), [FactKey::Refusal, FactKey::Slot, FactKey::Refusal]),
        );
    }

    public function test_a_missing_pull_request_differs_from_a_present_one(): void
    {
        $fingerprint = new FactFingerprint();
        $none = FactsMother::facts(card: FactsMother::card(slot: 'implementation', documents: self::documents()), pullRequests: self::all(), run: self::workRun());

        self::assertNotSame($fingerprint->of($this->facts(), [FactKey::PullRequest]), $fingerprint->of($none, [FactKey::PullRequest]));
    }

    private function facts(): Facts
    {
        return FactsMother::facts(
            card: FactsMother::card(slot: 'implementation', documents: self::documents()),
            pullRequest: self::primary(),
            pullRequests: self::all(),
            run: self::workRun(),
        );
    }

    /** @return list<DocumentFacts> */
    private static function documents(): array
    {
        return [new DocumentFacts(['product'], 'approved'), new DocumentFacts(['design', 'tech'], 'in_review')];
    }

    private static function primary(): PullRequestFacts
    {
        return FactsMother::pullRequest(checks: ChecksState::Passed);
    }

    /** @return list<PullRequestFacts> */
    private static function all(): array
    {
        return [self::primary(), FactsMother::pullRequest(state: PullRequestState::Closed)];
    }

    private static function workRun(): RunFacts
    {
        return FactsMother::run(['implement', 'review'], 'no-capacity');
    }
}
