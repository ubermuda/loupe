<?php

declare(strict_types=1);

namespace App\Tests\Outbox\Command;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\LiveUpdates;
use App\Mercure\ProjectTopicBuilder;
use App\Mercure\UserTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Bridge\BridgeEventType;
use App\Module\Project\Entity\Project;
use App\Module\Project\ProjectEventType;
use App\Outbox\ActivityChangedPublisher;
use App\Outbox\AgentPush;
use App\Outbox\Command\DrainOutboxCommand;
use App\Outbox\Command\DrainOutboxHandler;
use App\Outbox\Entity\OutboxEvent;
use App\Outbox\Repository\OutboxEventRepository;
use App\Tests\Support\FeatureFlags;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

final class DrainOutboxHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private HubInterface&MockObject $hub;
    private DrainOutboxHandler $handler;
    private OutboxEventRepository $outboxEvents;
    private UserTopicBuilder $userTopics;
    private ProjectTopicBuilder $projectTopics;
    private LiveUpdatePublisher $live;

    /** @var list<Update> */
    private array $signals = [];

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $outboxEvents = self::getContainer()->get(OutboxEventRepository::class);
        self::assertInstanceOf(OutboxEventRepository::class, $outboxEvents);
        $this->outboxEvents = $outboxEvents;
        $this->hub = $this->createMock(HubInterface::class);
        $userTopics = self::getContainer()->get(UserTopicBuilder::class);
        self::assertInstanceOf(UserTopicBuilder::class, $userTopics);
        $this->userTopics = $userTopics;
        $projectTopics = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $projectTopics);
        $this->projectTopics = $projectTopics;
        $this->signals = [];
        $this->live = new LiveUpdatePublisher(
            new RequestStack(),
            FeatureFlags::service([LiveUpdates::FLAG => true]),
            new NullLogger(),
            fn (): HubInterface => new MockHub('http://mercure/.well-known/mercure', new StaticTokenProvider('token'), function (Update $update): string {
                $this->signals[] = $update;

                return 'id';
            }),
        );
        $this->handler = $this->drain(pushEnabled: true);
    }

    public function test_it_signals_the_activity_of_each_drained_project_once(): void
    {
        $first = $this->project('drain-signal-a@example.com');
        $second = $this->project('drain-signal-b@example.com');
        $this->event($first);
        $this->event($first);
        $this->event($second);
        $this->em->flush();
        $this->hub->expects($this->exactly(3))->method('publish')->willReturn('id');

        ($this->handler)(new DrainOutboxCommand());
        $this->live->publish();

        self::assertEqualsCanonicalizing(
            [[$this->activityTopic($first)], [$this->activityTopic($second)]],
            array_map(static fn (Update $update): array => $update->getTopics(), $this->signals),
        );
        self::assertSame('{"type":"activity.changed","origin":null}', $this->signals[0]->getData());
    }

    public function test_only_the_types_a_bridge_runs_reach_the_hub_and_every_row_settles(): void
    {
        $project = $this->project('drain-types@example.com');
        $pushed = [BridgeEventType::WORK_REQUEST, BridgeEventType::COMMAND, ProjectEventType::RENAMED, BridgeEventType::CARD_HELD, BridgeEventType::CARD_RELEASED];
        $events = [];
        foreach ([...$pushed, 'board.card_moved', 'pull_request.merged'] as $type) {
            $events[] = $this->event($project, $type);
        }
        $this->em->flush();
        $sent = [];
        $this->hub->expects($this->exactly(5))->method('publish')
            ->willReturnCallback(static function (Update $update) use (&$sent): string {
                $sent[] = $update->getId();

                return 'id';
            });

        $result = ($this->handler)(new DrainOutboxCommand());
        $this->live->publish();

        self::assertSame(array_map(static fn (OutboxEvent $event): string => (string) $event->sequence, \array_slice($events, 0, 5)), $sent);
        self::assertSame(7, $result->published);
        $this->em->clear();
        foreach ($events as $event) {
            self::assertNotNull($this->outboxEvents->find($event->id)?->publishedAt);
        }
        self::assertSame([[$this->activityTopic($project)]], array_map(static fn (Update $update): array => $update->getTopics(), $this->signals));
    }

    public function test_a_failed_publish_still_signals_its_project(): void
    {
        $project = $this->project('drain-signal-failed@example.com');
        $this->event($project);
        $this->em->flush();
        $this->hub->expects($this->once())->method('publish')->willThrowException(new \RuntimeException('hub unreachable'));

        ($this->handler)(new DrainOutboxCommand());
        $this->live->publish();

        self::assertSame([[$this->activityTopic($project)]], array_map(static fn (Update $update): array => $update->getTopics(), $this->signals));
    }

    public function test_a_drain_that_claims_nothing_signals_nothing(): void
    {
        $this->hub->expects($this->never())->method('publish');

        ($this->handler)(new DrainOutboxCommand());
        ($this->drain(pushEnabled: false))(new DrainOutboxCommand());
        $this->live->publish();

        self::assertSame([], $this->signals);
    }

    public function test_push_disabled_claims_nothing_at_all(): void
    {
        $project = $this->project('drain-push-off@example.com');
        $event = $this->event($project);
        $this->em->flush();

        $this->hub->expects($this->never())->method('publish');

        $result = ($this->drain(pushEnabled: false))(new DrainOutboxCommand());

        self::assertSame(0, $result->published);
        self::assertSame(0, $result->failed);

        // Left untouched, not merely undelivered. Claiming it would have leased
        // the row and recorded a failed attempt, so turning push back on would
        // find it backed off rather than ready to go.
        $this->em->clear();
        $reloaded = $this->outboxEvents->find($event->id);
        self::assertNotNull($reloaded);
        self::assertSame(0, $reloaded->publishAttempts);
        self::assertNull($reloaded->publishedAt);
        self::assertNull($reloaded->nextAttemptAt);
    }

    public function test_a_stranded_event_is_republished_and_settled(): void
    {
        $project = $this->project('drain-a@example.com');
        $event = $this->event($project);
        $this->em->flush();

        $this->hub->expects($this->once())->method('publish')
            ->with(self::callback(fn (Update $update): bool => [$this->userTopics->forUser($project->owner->id ?? Uuid::v7())] === $update->getTopics()
                    && '{}' === $update->getData()
                    // The sequence rides along as the SSE id so a reconnecting
                    // subscriber can resume from it, exactly as on first publish.
                    && $event->sequence === $update->getId()))
            ->willReturn('id');

        $result = ($this->handler)(new DrainOutboxCommand());

        self::assertSame(1, $result->published);
        self::assertSame(0, $result->failed);

        $this->em->clear();
        $settled = $this->em->find(OutboxEvent::class, $event->id);
        self::assertNotNull($settled);
        self::assertNotNull($settled->publishedAt);
        self::assertSame(0, $this->outboxEvents->countUnsent($project));
    }

    /** The bridge follows one topic per user, so every owned project's event must reach it. */
    public function test_each_event_goes_only_to_its_project_owners_user_topic(): void
    {
        $first = $this->project('drain-owner@example.com');
        $second = new Project($first->owner, 'drain-second-'.bin2hex(random_bytes(4)));
        $this->em->persist($second);
        $foreign = $this->project('drain-foreign@example.com');
        $this->event($first);
        $this->event($second);
        $this->event($foreign);
        $this->em->flush();

        $published = [];
        $this->hub->expects($this->exactly(3))->method('publish')
            ->willReturnCallback(static function (Update $update) use (&$published): string {
                $published[] = $update->getTopics();

                return 'id';
            });

        ($this->handler)(new DrainOutboxCommand());

        $ownerTopic = $this->userTopics->forUser($first->owner->id ?? Uuid::v7());
        $foreignTopic = $this->userTopics->forUser($foreign->owner->id ?? Uuid::v7());
        self::assertCount(2, array_filter($published, static fn (array $topics): bool => \in_array($ownerTopic, $topics, true)));
        self::assertCount(1, array_filter($published, static fn (array $topics): bool => \in_array($foreignTopic, $topics, true)));
        foreach ($published as $topics) {
            self::assertCount(1, $topics);
            self::assertNotContains('https://app/topic', $topics);
        }
    }

    public function test_an_already_published_event_is_not_published_twice(): void
    {
        $project = $this->project('drain-c@example.com');
        $event = $this->event($project);
        $event->markPublished();
        $this->em->flush();

        $this->hub->expects($this->never())->method('publish');

        self::assertSame(0, ($this->handler)(new DrainOutboxCommand())->published);
    }

    public function test_a_failing_publish_records_the_error_and_backs_the_row_off(): void
    {
        $project = $this->project('drain-d@example.com');
        $event = $this->event($project);
        $this->em->flush();

        $this->hub->expects($this->once())->method('publish')
            ->willThrowException(new \RuntimeException('hub unreachable'));

        $result = ($this->handler)(new DrainOutboxCommand());

        self::assertSame(0, $result->published);
        self::assertSame(1, $result->failed);

        $this->em->clear();
        $stuck = $this->em->find(OutboxEvent::class, $event->id);
        self::assertNotNull($stuck);
        self::assertNull($stuck->publishedAt, 'A failed publish must leave the row replayable.');
        self::assertSame(1, $stuck->publishAttempts);
        self::assertSame('hub unreachable', $stuck->lastPublishError);
        self::assertNotNull($stuck->nextAttemptAt);
        self::assertGreaterThan(new \DateTimeImmutable(), $stuck->nextAttemptAt);
    }

    public function test_a_backed_off_row_is_left_alone_until_it_is_due(): void
    {
        $project = $this->project('drain-e@example.com');
        $this->event($project);
        $this->em->flush();

        $this->hub->expects($this->once())->method('publish')
            ->willThrowException(new \RuntimeException('hub unreachable'));

        ($this->handler)(new DrainOutboxCommand());

        // Second pass in the same minute: the backoff has not elapsed, so the
        // hub mock's single-call expectation is what proves nothing retried.
        self::assertSame(0, ($this->handler)(new DrainOutboxCommand())->failed);
    }

    private function drain(bool $pushEnabled): DrainOutboxHandler
    {
        return new DrainOutboxHandler(
            $this->outboxEvents,
            $this->em,
            $this->hub,
            new NullLogger(),
            FeatureFlags::service([AgentPush::FLAG => $pushEnabled]),
            $this->userTopics,
            new ActivityChangedPublisher($this->projectTopics, $this->live),
        );
    }

    private function activityTopic(Project $project): string
    {
        return $this->projectTopics->forActivity($project->id ?? throw new \LogicException('The project has no id.'));
    }

    private function event(Project $project, string $type = BridgeEventType::COMMAND): OutboxEvent
    {
        $event = new OutboxEvent($project, $type, 'https://app/topic', '{}');
        $this->em->persist($event);

        return $event;
    }

    /** @param non-empty-string $email */
    private function project(string $email): Project
    {
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $this->em->persist($user);
        $project = new Project($user, 'drain-'.bin2hex(random_bytes(4)));
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
