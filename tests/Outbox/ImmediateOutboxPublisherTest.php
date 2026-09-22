<?php

declare(strict_types=1);

namespace App\Tests\Outbox;

use App\Mercure\UserTopicBuilder;
use App\Outbox\Command\DrainOutboxHandler;
use App\Outbox\ImmediateOutboxPublisher;
use App\Outbox\Repository\OutboxEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * DrainOutboxHandler is final, so these count the calls to the service closure
 * instead of doubling the handler. The closure runs only on the branch that
 * drains, which is the behaviour under test. The handler it returns reads the
 * push flag as off and so touches nothing.
 */
#[CoversClass(ImmediateOutboxPublisher::class)]
final class ImmediateOutboxPublisherTest extends TestCase
{
    private int $drains = 0;

    protected function setUp(): void
    {
        $this->drains = 0;
    }

    public function test_it_drains_once_when_a_row_was_written(): void
    {
        $publisher = $this->publisher();
        $publisher->rowWritten();
        $publisher->publish();

        self::assertSame(1, $this->drains);
    }

    public function test_it_drains_once_for_several_rows_of_one_request(): void
    {
        $publisher = $this->publisher();
        $publisher->rowWritten();
        $publisher->rowWritten();
        $publisher->publish();

        self::assertSame(1, $this->drains);
    }

    public function test_it_drains_nothing_when_no_row_was_written(): void
    {
        $this->publisher()->publish();

        self::assertSame(0, $this->drains);
    }

    /**
     * A second terminate in one worker must not publish again. publish() clears
     * the flag before it drains, which is what this proves.
     */
    public function test_a_second_terminate_drains_nothing(): void
    {
        $publisher = $this->publisher();
        $publisher->rowWritten();
        $publisher->publish();
        $publisher->publish();

        self::assertSame(1, $this->drains);
    }

    public function test_reset_drops_a_pending_row(): void
    {
        $publisher = $this->publisher();
        $publisher->rowWritten();
        $publisher->reset();
        $publisher->publish();

        self::assertSame(0, $this->drains);
    }

    /**
     * A hub that is down must never break the request that wrote the row. The
     * cron drain is what retries it.
     */
    public function test_a_failed_drain_is_logged_and_swallowed(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('outbox.immediate_publish_failed', ['error' => 'hub down']);

        $publisher = new ImmediateOutboxPublisher($logger, function (): DrainOutboxHandler {
            throw new \RuntimeException('hub down');
        });
        $publisher->rowWritten();
        $publisher->publish();
    }

    private function publisher(): ImmediateOutboxPublisher
    {
        return new ImmediateOutboxPublisher(new NullLogger(), function (): DrainOutboxHandler {
            ++$this->drains;

            return $this->handler();
        });
    }

    private function handler(): DrainOutboxHandler
    {
        $flags = $this->createStub(FeatureFlagService::class);
        $flags->method('isEnabled')->willReturn(false);

        return new DrainOutboxHandler(
            $this->createStub(OutboxEventRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(HubInterface::class),
            new NullLogger(),
            $flags,
            new UserTopicBuilder('https://loupe.test'),
        );
    }
}
