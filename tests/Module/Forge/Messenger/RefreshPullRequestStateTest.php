<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Messenger;

use App\Module\Forge\Messenger\RefreshPullRequestState;
use PHPUnit\Framework\TestCase;

final class RefreshPullRequestStateTest extends TestCase
{
    /** A message the Doctrine transport stored before the review marker existed. */
    private const string QUEUED_WITHOUT_MARKER = 'O:50:"App\Module\Forge\Messenger\RefreshPullRequestState":2:{s:13:"pullRequestId";s:36:"0199a0b8-0000-7000-8000-000000000000";s:11:"requestedAt";O:17:"DateTimeImmutable":3:{s:4:"date";s:26:"2026-09-27 12:00:00.000000";s:13:"timezone_type";i:3;s:8:"timezone";s:3:"UTC";}}';

    public function test_a_message_queued_without_the_marker_reads_as_a_plain_refresh(): void
    {
        $message = unserialize(self::QUEUED_WITHOUT_MARKER);

        self::assertInstanceOf(RefreshPullRequestState::class, $message);
        self::assertSame('0199a0b8-0000-7000-8000-000000000000', $message->pullRequestId);
        self::assertEquals(new \DateTimeImmutable('2026-09-27 12:00:00', new \DateTimeZone('UTC')), $message->requestedAt);
        self::assertFalse($message->reviewSubmitted);
    }

    public function test_the_marker_survives_a_round_trip(): void
    {
        $message = unserialize(serialize(new RefreshPullRequestState('id', new \DateTimeImmutable(), reviewSubmitted: true)));

        self::assertInstanceOf(RefreshPullRequestState::class, $message);
        self::assertTrue($message->reviewSubmitted);
    }
}
