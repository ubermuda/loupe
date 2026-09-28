<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Messenger;

use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Messenger\RefreshPullRequestState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefreshPullRequestStateTest extends TestCase
{
    /** A message the Doctrine transport stored before the review marker existed. */
    private const string QUEUED_WITHOUT_MARKER = 'O:50:"App\Module\Forge\Messenger\RefreshPullRequestState":2:{s:13:"pullRequestId";s:36:"0199a0b8-0000-7000-8000-000000000000";s:11:"requestedAt";O:17:"DateTimeImmutable":3:{s:4:"date";s:26:"2026-09-27 12:00:00.000000";s:13:"timezone_type";i:3;s:8:"timezone";s:3:"UTC";}}';

    /** A message the Doctrine transport stored while the marker was a bool. */
    private const string QUEUED_WITH_BOOL_MARKER = 'O:50:"App\Module\Forge\Messenger\RefreshPullRequestState":3:{s:13:"pullRequestId";s:36:"0199a0b8-0000-7000-8000-000000000000";s:11:"requestedAt";O:17:"DateTimeImmutable":3:{s:4:"date";s:26:"2026-09-27 12:00:00.000000";s:13:"timezone_type";i:3;s:8:"timezone";s:3:"UTC";}s:15:"reviewSubmitted";b:1;}';

    /** @return iterable<string, array{string}> */
    public static function oldPayloads(): iterable
    {
        yield 'no marker' => [self::QUEUED_WITHOUT_MARKER];
        yield 'bool marker' => [self::QUEUED_WITH_BOOL_MARKER];
    }

    #[DataProvider('oldPayloads')]
    public function test_an_old_message_reads_as_a_refresh_with_no_verdict(string $payload): void
    {
        $message = unserialize($payload);

        self::assertInstanceOf(RefreshPullRequestState::class, $message);
        self::assertSame('0199a0b8-0000-7000-8000-000000000000', $message->pullRequestId);
        self::assertEquals(new \DateTimeImmutable('2026-09-27 12:00:00', new \DateTimeZone('UTC')), $message->requestedAt);
        self::assertNull($message->verdict);
    }

    public function test_the_verdict_survives_a_round_trip(): void
    {
        $message = unserialize(serialize(new RefreshPullRequestState('id', new \DateTimeImmutable(), PullRequestReview::ChangesRequested)));

        self::assertInstanceOf(RefreshPullRequestState::class, $message);
        self::assertSame(PullRequestReview::ChangesRequested, $message->verdict);
    }
}
