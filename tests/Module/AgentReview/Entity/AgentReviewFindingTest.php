<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Entity;

use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\AgentReview\Entity\AgentReviewSeverity;
use PHPUnit\Framework\TestCase;

final class AgentReviewFindingTest extends TestCase
{
    public function test_to_array_and_from_array_round_trip(): void
    {
        $finding = new AgentReviewFinding('src/Foo.php', 3, 5, AgentReviewSeverity::PreExisting, 'Old bug', 'It was already there.');

        $array = $finding->toArray();

        self::assertSame(['path' => 'src/Foo.php', 'startLine' => 3, 'endLine' => 5, 'severity' => 'pre-existing', 'title' => 'Old bug', 'body' => 'It was already there.'], $array);
        self::assertEquals($finding, AgentReviewFinding::fromArray($array));
    }

    public function test_a_line_range_must_start_at_one_or_later(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AgentReviewFinding('src/Foo.php', 0, 5, AgentReviewSeverity::Nit, 'Title', 'Body');
    }

    public function test_a_line_range_must_not_end_before_it_starts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AgentReviewFinding('src/Foo.php', 5, 4, AgentReviewSeverity::Nit, 'Title', 'Body');
    }
}
