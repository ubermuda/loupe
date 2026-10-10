<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Entity;

use App\Module\AgentReview\Entity\AgentReviewCategory;
use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\AgentReview\Entity\AgentReviewSeverity;
use PHPUnit\Framework\TestCase;

final class AgentReviewFindingTest extends TestCase
{
    public function test_to_array_and_from_array_round_trip(): void
    {
        $finding = new AgentReviewFinding('src/Foo.php', 3, 5, AgentReviewSeverity::PreExisting, 'Old bug', 'It was already there.');

        $array = $finding->toArray();

        self::assertSame(['path' => 'src/Foo.php', 'startLine' => 3, 'endLine' => 5, 'severity' => 'pre-existing', 'title' => 'Old bug', 'body' => 'It was already there.', 'category' => 'code'], $array);
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

    public function test_a_stored_finding_with_no_category_reads_as_code(): void
    {
        $finding = AgentReviewFinding::fromArray(['path' => 'src/Foo.php', 'startLine' => 3, 'endLine' => 5, 'severity' => 'nit', 'title' => 'T', 'body' => 'B']);

        self::assertSame(AgentReviewCategory::Code, $finding->category);
    }

    public function test_a_spec_finding_can_have_no_path_and_no_lines(): void
    {
        $finding = new AgentReviewFinding(null, null, null, AgentReviewSeverity::Important, 'Requirement not built', 'No size limit.', AgentReviewCategory::Spec);

        self::assertEquals($finding, AgentReviewFinding::fromArray($finding->toArray()));
        self::assertNull($finding->toArray()['path']);
    }

    public function test_a_code_finding_must_have_a_path(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AgentReviewFinding(null, null, null, AgentReviewSeverity::Nit, 'Title', 'Body');
    }

    public function test_a_finding_with_lines_must_have_a_path(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AgentReviewFinding(null, 1, 2, AgentReviewSeverity::Nit, 'Title', 'Body', AgentReviewCategory::Spec);
    }
}
