<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Workflow;

use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Workflow\AgentReviewFacts;
use App\Module\AgentReview\Workflow\Condition\AgentReviewDue;
use App\Module\AgentReview\Workflow\Condition\AgentReviewFailed;
use App\Module\AgentReview\Workflow\Condition\AgentReviewPassed;
use App\Module\AgentReview\Workflow\Condition\AgentReviewUnposted;
use App\Module\AgentReview\Workflow\ReviewedHead;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AgentReviewConditionsTest extends TestCase
{
    /** @return iterable<string, array{Condition, AgentReviewFacts, bool}> */
    public static function cases(): iterable
    {
        $none = self::head(null);
        $success = self::head(AgentReviewConclusion::Success);
        $failure = self::head(AgentReviewConclusion::Failure);

        yield 'due: switch off' => [new AgentReviewDue(), self::review([$none], enabled: false), false];
        yield 'due: epic' => [new AgentReviewDue(), self::review([$none], epic: true), false];
        yield 'due: no pull request head' => [new AgentReviewDue(), self::review([]), false];
        yield 'due: head without review' => [new AgentReviewDue(), self::review([$success, $none]), true];
        yield 'due: every head reviewed' => [new AgentReviewDue(), self::review([$success, $failure]), false];

        yield 'failed: switch off' => [new AgentReviewFailed(), self::review([$failure], enabled: false), false];
        yield 'failed: head without review' => [new AgentReviewFailed(), self::review([$none]), false];
        yield 'failed: every head passed' => [new AgentReviewFailed(), self::review([$success]), false];
        yield 'failed: one head failed' => [new AgentReviewFailed(), self::review([$success, $failure]), true];

        yield 'passed: switch off' => [new AgentReviewPassed(), self::review([$none], enabled: false), true];
        yield 'passed: epic' => [new AgentReviewPassed(), self::review([], epic: true), true];
        yield 'passed: no pull request head' => [new AgentReviewPassed(), self::review([]), false];
        yield 'passed: head without review' => [new AgentReviewPassed(), self::review([$success, $none]), false];
        yield 'passed: one head failed' => [new AgentReviewPassed(), self::review([$success, $failure]), false];
        yield 'passed: every head passed' => [new AgentReviewPassed(), self::review([$success, self::head(AgentReviewConclusion::Success, 'other')]), true];

        yield 'unposted: switch off' => [new AgentReviewUnposted(), self::review([], enabled: false, unposted: true), false];
        yield 'unposted: all posted' => [new AgentReviewUnposted(), self::review([$success]), false];
        yield 'unposted: a review waits' => [new AgentReviewUnposted(), self::review([$success], unposted: true), true];
    }

    #[DataProvider('cases')]
    public function test_the_condition_reads_the_agent_review_facts(Condition $condition, AgentReviewFacts $review, bool $expected): void
    {
        self::assertSame([AgentReviewFacts::class], $condition->reads([]));
        self::assertSame($expected, $condition->evaluate(self::facts($review), []));
    }

    public function test_each_condition_has_a_key_and_a_waiting_message_for_both_senses(): void
    {
        foreach ([new AgentReviewDue(), new AgentReviewFailed(), new AgentReviewPassed(), new AgentReviewUnposted()] as $condition) {
            self::assertStringStartsWith('agent_review.', $condition::key());
            self::assertSame('workflow.source.agent_review', $condition::source());
            self::assertNotSame($condition->waitingFor([])->getMessage(), $condition->waitingFor([], negated: true)->getMessage());
        }
    }

    private static function head(?AgentReviewConclusion $conclusion, string $pullRequestId = 'pull-request'): ReviewedHead
    {
        return new ReviewedHead($pullRequestId, str_repeat('a', 40), $conclusion);
    }

    /** @param list<ReviewedHead> $heads */
    private static function review(array $heads, bool $enabled = true, bool $epic = false, bool $unposted = false): AgentReviewFacts
    {
        return new AgentReviewFacts($heads, $enabled, $epic, $unposted);
    }

    private static function facts(AgentReviewFacts $review): Facts
    {
        return FactsMother::facts(provided: [AgentReviewFacts::class => $review]);
    }
}
