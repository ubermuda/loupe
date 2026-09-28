<?php

declare(strict_types=1);

namespace App\Tests\Outbox;

use App\Outbox\ActivityFamily;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ActivityFamilyTest extends TestCase
{
    /** @return iterable<string, array{string, ?ActivityFamily}> */
    public static function types(): iterable
    {
        yield 'board' => ['board.card_moved', ActivityFamily::Board];
        yield 'document' => ['document.review_submitted', ActivityFamily::Document];
        yield 'legacy review folds into document' => ['review.submitted', ActivityFamily::Document];
        yield 'pull request' => ['pull_request.merged', ActivityFamily::PullRequest];
        yield 'project' => ['project.renamed', ActivityFamily::Project];
        yield 'site review' => ['site_review.batch_submitted', ActivityFamily::SiteReview];
        yield 'unknown prefix' => ['billing.paid', null];
        yield 'no dot' => ['board', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('types')]
    public function test_from_type_reads_the_prefix_before_the_first_dot(string $type, ?ActivityFamily $expected): void
    {
        self::assertSame($expected, ActivityFamily::fromType($type));
    }

    public function test_document_matches_the_legacy_review_prefix(): void
    {
        self::assertSame(['document.', 'review.'], ActivityFamily::Document->typePrefixes());
    }

    public function test_every_other_family_matches_its_own_prefix(): void
    {
        self::assertSame(['pull_request.'], ActivityFamily::PullRequest->typePrefixes());
        self::assertSame(['board.'], ActivityFamily::Board->typePrefixes());
    }

    public function test_every_prefix_maps_back_to_its_family(): void
    {
        foreach (ActivityFamily::cases() as $family) {
            foreach ($family->typePrefixes() as $prefix) {
                self::assertSame($family, ActivityFamily::fromType($prefix.'x'));
            }
        }
    }

    public function test_translation_key(): void
    {
        self::assertSame('activity.family.site_review', ActivityFamily::SiteReview->translationKey());
    }
}
