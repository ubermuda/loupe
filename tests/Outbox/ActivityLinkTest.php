<?php

declare(strict_types=1);

namespace App\Tests\Outbox;

use App\Outbox\ActivityLink;
use PHPUnit\Framework\TestCase;

final class ActivityLinkTest extends TestCase
{
    public function test_the_subject_defaults_to_the_label(): void
    {
        self::assertSame('#7 Fix login', new ActivityLink('/cards/7', '#7 Fix login')->subject);
    }

    public function test_a_given_subject_replaces_the_label_as_subject_only(): void
    {
        $link = new ActivityLink('/cards/7', '#7 Fix login', '#7 Backlog → Done');

        self::assertSame('#7 Fix login', $link->label);
        self::assertSame('#7 Backlog → Done', $link->subject);
    }
}
