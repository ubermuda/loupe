<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Entity;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewEvent;
use PHPUnit\Framework\TestCase;

final class SiteReviewEventTest extends TestCase
{
    public function test_backoff_doubles_per_attempt_and_stops_at_an_hour(): void
    {
        $event = $this->event();
        $now = new \DateTimeImmutable('2026-01-01 00:00:00');

        $waits = [];
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $event->recordPublishFailure('boom', $now);
            self::assertNotNull($event->nextAttemptAt);
            $waits[] = intdiv($event->nextAttemptAt->getTimestamp() - $now->getTimestamp(), 60);
        }

        self::assertSame([1, 2, 4, 8, 16, 32, 60, 60], $waits);
        self::assertSame(8, $event->publishAttempts, 'The outbox never stops retrying a row.');
    }

    public function test_a_recorded_error_is_truncated_so_one_bad_response_cannot_swamp_the_page(): void
    {
        $event = $this->event();

        $event->recordPublishFailure(str_repeat('x', 5000), new \DateTimeImmutable());

        self::assertNotNull($event->lastPublishError);
        self::assertSame(500, mb_strlen($event->lastPublishError));
    }

    private function event(): SiteReviewEvent
    {
        $owner = new User(username: 'u@example.com', fullName: 'U', email: 'u@example.com', password: 'x');

        return new SiteReviewEvent(new Project($owner, 'site'), 'https://app/topic', '{}');
    }
}
