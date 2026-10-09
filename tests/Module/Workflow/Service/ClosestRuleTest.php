<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Workflow\Condition\CardHasOpenBlocker;
use App\Module\Workflow\Condition\CardHasType;
use App\Module\Workflow\Condition\CardIsChild;
use App\Module\Workflow\Condition\PullRequestApprovalCoversHead;
use App\Module\Workflow\Condition\PullRequestBaseIsEpicBranch;
use App\Module\Workflow\Condition\PullRequestBehind;
use App\Module\Workflow\Condition\PullRequestChecksPassed;
use App\Module\Workflow\Condition\PullRequestDraft;
use App\Module\Workflow\Condition\PullRequestOpen;
use App\Module\Workflow\Contract\ChecksState;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\PullRequestState;
use App\Module\Workflow\Expression\AllOf;
use App\Module\Workflow\Expression\ConditionLeaf;
use App\Module\Workflow\Expression\Expression;
use App\Module\Workflow\Expression\Not;
use App\Module\Workflow\Service\ClosestRule;
use App\Module\Workflow\Template\ActionCall;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\Rule;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ClosestRuleTest extends TestCase
{
    public function test_an_empty_list_has_no_closest_rule(): void
    {
        self::assertNull(new ClosestRule()->find([], FactsMother::facts()));
    }

    public function test_the_rule_with_the_fewest_false_conditions_wins_over_template_order(): void
    {
        $far = self::rule('far', new AllOf([self::leaf(new CardIsChild()), self::leaf(new CardHasOpenBlocker())]));
        $near = self::rule('near', new AllOf([self::leaf(new CardIsChild()), new Not(self::leaf(new CardHasOpenBlocker()))]));

        self::assertSame($near, new ClosestRule()->find([$far, $near], FactsMother::facts())?->rule);
    }

    public function test_a_tie_goes_to_the_earlier_rule_whatever_its_size(): void
    {
        $small = self::rule('small', self::leaf(new CardIsChild()));
        $large = self::rule('large', new AllOf([self::leaf(new CardIsChild()), new Not(self::leaf(new CardHasOpenBlocker()))]));

        self::assertSame($small, new ClosestRule()->find([$small, $large], FactsMother::facts())?->rule);
        self::assertSame($large, new ClosestRule()->find([$large, $small], FactsMother::facts())?->rule);
    }

    public function test_an_epic_child_merge_rule_one_condition_away_wins_over_an_earlier_merge_rule_two_away(): void
    {
        $pullRequest = FactsMother::pullRequest(behind: true, baseIsEpicBranch: true, id: Uuid::v7());
        $facts = FactsMother::facts(pullRequest: $pullRequest, pullRequests: [$pullRequest]);
        $mergeReady = self::rule('merge-ready', new AllOf([
            self::leaf(new PullRequestOpen()),
            new Not(self::leaf(new PullRequestDraft())),
            self::leaf(new PullRequestApprovalCoversHead(), ['min' => 1]),
            new Not(self::leaf(new PullRequestBaseIsEpicBranch())),
        ]));
        $epicChild = self::rule('merge-ready-epic-child', new AllOf([
            self::leaf(new PullRequestOpen()),
            new Not(self::leaf(new PullRequestDraft())),
            self::leaf(new PullRequestBaseIsEpicBranch()),
            new Not(self::leaf(new PullRequestBehind())),
        ]));

        self::assertSame($epicChild, new ClosestRule()->find([$mergeReady, $epicChild], $facts)?->rule);
    }

    public function test_a_pull_request_rule_binds_the_open_pull_request_with_the_fewest_false_conditions(): void
    {
        $merged = FactsMother::pullRequest(state: PullRequestState::Merged, checks: ChecksState::Passed, id: Uuid::v7());
        $far = FactsMother::pullRequest(draft: true, checks: ChecksState::Failed, id: Uuid::v7());
        $near = FactsMother::pullRequest(draft: true, checks: ChecksState::Passed, id: Uuid::v7());
        $facts = FactsMother::facts(pullRequest: $far, pullRequests: [$merged, $far, $near]);
        $ready = self::rule('ready', new AllOf([new Not(self::leaf(new PullRequestDraft())), self::leaf(new PullRequestChecksPassed())]));
        $epic = self::rule('epic', new AllOf([self::leaf(new CardHasType(), ['type' => 'epic']), self::leaf(new CardIsChild())]));

        $match = new ClosestRule()->find([$epic, $ready], $facts);

        self::assertSame($ready, $match?->rule);
        self::assertSame($near, $match->facts->pullRequest);
        self::assertSame([$merged, $far, $near], $match->facts->pullRequests);
    }

    public function test_a_rule_that_reads_no_pull_request_keeps_the_facts_as_built(): void
    {
        $open = FactsMother::pullRequest(id: Uuid::v7());
        $facts = FactsMother::facts(pullRequest: $open, pullRequests: [$open]);
        $child = self::rule('child', self::leaf(new CardIsChild()));

        self::assertSame($facts, new ClosestRule()->find([$child], $facts)?->facts);
    }

    /** @param array<string, mixed> $params */
    private static function leaf(Condition $condition, array $params = []): ConditionLeaf
    {
        return new ConditionLeaf($condition, $params);
    }

    private static function rule(string $id, Expression $when): Rule
    {
        return new Rule($id, null, $when, new ActionCall(ActionType::Request, ['kind' => 'fix']));
    }
}
