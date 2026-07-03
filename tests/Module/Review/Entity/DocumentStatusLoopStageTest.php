<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Entity;

use App\LoopStage;
use App\Module\Review\Entity\DocumentStatus;
use PHPUnit\Framework\TestCase;

final class DocumentStatusLoopStageTest extends TestCase
{
    public function test_in_review_maps_to_in_review_stage(): void
    {
        self::assertSame(LoopStage::InReview, DocumentStatus::InReview->loopStage());
    }

    public function test_changes_requested_maps_to_revise_stage(): void
    {
        self::assertSame(LoopStage::Revise, DocumentStatus::ChangesRequested->loopStage());
    }

    public function test_approved_maps_to_approved_stage(): void
    {
        self::assertSame(LoopStage::Approved, DocumentStatus::Approved->loopStage());
    }

    public function test_translation_key_uses_underscore_not_backing_value(): void
    {
        // Guards against deriving the key from the hyphenated backing value.
        self::assertSame('loop.step.in_review', LoopStage::InReview->translationKey());
    }
}
