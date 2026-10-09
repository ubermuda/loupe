<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Entity;

use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\Board\Entity\BoardAutomationSettings;
use PHPUnit\Framework\TestCase;

final class AgentReviewSeverityTest extends TestCase
{
    /** Board must not import AgentReview, so it keeps its own copy of the values for the settings form. */
    public function test_the_board_settings_offer_exactly_the_severities_of_a_finding(): void
    {
        self::assertSame(
            array_map(static fn (AgentReviewSeverity $severity): string => $severity->value, AgentReviewSeverity::cases()),
            BoardAutomationSettings::AGENT_REVIEW_SEVERITIES,
        );
    }
}
