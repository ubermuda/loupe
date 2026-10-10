<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Entity;

use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\Workflow\Template\Template;
use PHPUnit\Framework\TestCase;

final class AgentReviewSeverityTest extends TestCase
{
    /** Workflow must not import AgentReview, so the template keeps its own copy of the values. */
    public function test_a_workflow_template_takes_exactly_the_severities_of_a_finding(): void
    {
        self::assertSame(
            array_map(static fn (AgentReviewSeverity $severity): string => $severity->value, AgentReviewSeverity::cases()),
            Template::AGENT_REVIEW_SEVERITIES,
        );
    }
}
