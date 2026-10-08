<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\EventListener;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\LiveUpdates;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\EventListener\PublishCardWarningOnWorkerRunChanged;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Tests\Support\FeatureFlags;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

final class PublishCardWarningOnWorkerRunChangedTest extends TestCase
{
    /** @var list<Update> */
    private array $published = [];

    public function test_each_card_of_the_change_signals_the_board(): void
    {
        $projectId = Uuid::v7();
        $first = Uuid::v7();
        $second = Uuid::v7();

        $this->publish(new WorkerRunChanged($projectId, [(string) $first, (string) $second], [(string) Uuid::v7()]));

        $board = new ProjectTopicBuilder('https://loupe.test')->forBoard($projectId);
        self::assertSame([[$board], [$board]], array_map(static fn (Update $update): array => $update->getTopics(), $this->published));
        self::assertSame(
            [
                '{"type":"worker_run.card_warning_changed","cardId":"'.$first.'","origin":null}',
                '{"type":"worker_run.card_warning_changed","cardId":"'.$second.'","origin":null}',
            ],
            array_map(static fn (Update $update): string => $update->getData(), $this->published),
        );
    }

    public function test_a_change_of_runs_about_no_card_signals_no_board(): void
    {
        $this->publish(new WorkerRunChanged(Uuid::v7(), [(string) Uuid::v7()], [(string) Uuid::v7()]));
        // Guard: the listener signals a change that names a card.
        self::assertCount(1, $this->published);

        $this->publish(new WorkerRunChanged(Uuid::v7(), [], [(string) Uuid::v7()]));

        self::assertCount(1, $this->published);
    }

    private function publish(WorkerRunChanged $event): void
    {
        $live = new LiveUpdatePublisher(
            new RequestStack(),
            FeatureFlags::service([LiveUpdates::FLAG => true]),
            new NullLogger(),
            fn (): HubInterface => new MockHub(
                'http://mercure/.well-known/mercure',
                new StaticTokenProvider('token'),
                function (Update $update): string {
                    $this->published[] = $update;

                    return 'id';
                },
            ),
        );

        new PublishCardWarningOnWorkerRunChanged(new WorkerRunChangedPublisher(new ProjectTopicBuilder('https://loupe.test'), $live))($event);
        $live->publish();
    }
}
