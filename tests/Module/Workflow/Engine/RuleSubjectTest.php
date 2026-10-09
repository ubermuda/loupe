<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Engine;

use App\Module\Workflow\Condition\CardHasType;
use App\Module\Workflow\Condition\PullRequestChecksFailed;
use App\Module\Workflow\Contract\ChecksState;
use App\Module\Workflow\Contract\PullRequestState;
use App\Module\Workflow\Engine\RuleSubject;
use App\Module\Workflow\Expression\ConditionLeaf;
use App\Module\Workflow\Expression\Expression;
use App\Module\Workflow\Template\ActionCall;
use App\Module\Workflow\Template\Rule;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class RuleSubjectTest extends TestCase
{
    public function test_a_rule_that_reads_the_pull_request_binds_the_first_open_one_that_makes_it_true(): void
    {
        $merged = FactsMother::pullRequest(state: PullRequestState::Merged, checks: ChecksState::Failed, id: Uuid::v7());
        $green = FactsMother::pullRequest(checks: ChecksState::Passed, id: Uuid::v7());
        $red = FactsMother::pullRequest(checks: ChecksState::Failed, stacked: true, id: Uuid::v7());
        $facts = FactsMother::facts(pullRequest: $green, pullRequests: [$merged, $green, $red]);

        $bound = new RuleSubject()->bind(self::rule(new ConditionLeaf(new PullRequestChecksFailed(), [])), $facts);

        self::assertTrue($bound->truth);
        self::assertTrue($bound->binds);
        self::assertSame($red, $bound->facts->pullRequest);
        self::assertSame($red->id, $bound->subject);
        self::assertSame([$merged, $green, $red], $bound->facts->pullRequests);
    }

    public function test_a_rule_that_no_open_pull_request_makes_true_binds_the_first_open_one(): void
    {
        $merged = FactsMother::pullRequest(state: PullRequestState::Merged, checks: ChecksState::Failed, id: Uuid::v7());
        $first = FactsMother::pullRequest(checks: ChecksState::Passed, id: Uuid::v7());
        $second = FactsMother::pullRequest(checks: ChecksState::Pending, stacked: true, id: Uuid::v7());
        $facts = FactsMother::facts(pullRequest: $second, pullRequests: [$merged, $first, $second]);

        $bound = new RuleSubject()->bind(self::rule(new ConditionLeaf(new PullRequestChecksFailed(), [])), $facts);

        self::assertFalse($bound->truth);
        self::assertSame($first, $bound->facts->pullRequest);
        self::assertSame($first->id, $bound->subject);
    }

    public function test_a_card_with_no_open_pull_request_keeps_the_facts_as_built(): void
    {
        $merged = FactsMother::pullRequest(state: PullRequestState::Merged, checks: ChecksState::Failed, id: Uuid::v7());
        $facts = FactsMother::facts(pullRequest: $merged, pullRequests: [$merged]);

        $bound = new RuleSubject()->bind(self::rule(new ConditionLeaf(new PullRequestChecksFailed(), [])), $facts);

        self::assertTrue($bound->truth);
        self::assertSame($facts, $bound->facts);
        self::assertSame($merged->id, $bound->subject);
    }

    public function test_a_rule_that_does_not_read_the_pull_request_keeps_the_facts_and_does_not_bind(): void
    {
        $first = FactsMother::pullRequest(checks: ChecksState::Failed, id: Uuid::v7());
        $primary = FactsMother::pullRequest(id: Uuid::v7());
        $facts = FactsMother::facts(card: FactsMother::card(type: 'epic'), pullRequest: $primary, pullRequests: [$first, $primary]);

        $bound = new RuleSubject()->bind(self::rule(new ConditionLeaf(new CardHasType(), ['type' => 'epic'])), $facts);

        self::assertTrue($bound->truth);
        self::assertFalse($bound->binds);
        self::assertSame($facts, $bound->facts);
        self::assertSame($primary->id, $bound->subject);
    }

    private static function rule(Expression $when): Rule
    {
        return new Rule('rule', null, $when, new ActionCall('request', ['kind' => 'fix']));
    }
}
