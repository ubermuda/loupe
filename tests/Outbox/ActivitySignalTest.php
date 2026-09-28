<?php

declare(strict_types=1);

namespace App\Tests\Outbox;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Event\ProjectRenamed;
use App\Module\Project\EventListener\WriteOutboxEventOnProjectRenamed;
use App\Module\Project\ProjectEventType;
use App\Outbox\ImmediateOutboxPublisher;
use App\Outbox\OutboxWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

final class ActivitySignalTest extends KernelTestCase
{
    /** @var list<Update> */
    private array $published = [];

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        // Before anything builds the hub: the container hands the replacement only to what it builds afterwards.
        self::getContainer()->set('mercure.hub.default', new MockHub(
            'http://mercure/.well-known/mercure',
            new StaticTokenProvider('token'),
            function (Update $update): string {
                $this->published[] = $update;

                return 'id';
            },
        ));
    }

    public function test_a_written_event_signals_the_activity_of_its_project_at_terminate(): void
    {
        $em = $this->service(EntityManagerInterface::class);
        $owner = new User(fullName: 'U', email: 'activity-signal@example.com', password: 'x');
        $project = new Project($owner, 'Activity signal');
        $em->persist($owner);
        $em->persist($project);
        $em->flush();

        $this->service(OutboxWriter::class)->write($project, 'board.card_moved', ['type' => 'board.card_moved']);
        $em->flush();
        self::assertSame([], $this->activitySignals($project));

        self::assertNotNull(self::$kernel);
        $this->service(EventDispatcherInterface::class)->dispatch(new TerminateEvent(self::$kernel, Request::create('/'), new Response()), KernelEvents::TERMINATE);

        $signals = $this->activitySignals($project);
        self::assertCount(1, $signals);
        self::assertTrue($signals[0]->isPrivate());
        self::assertSame('{"type":"activity.changed","origin":null}', $signals[0]->getData());
    }

    public function test_a_project_rename_signals_the_activity_of_its_project_at_terminate(): void
    {
        $em = $this->service(EntityManagerInterface::class);
        $owner = new User(fullName: 'U', email: 'activity-rename-signal@example.com', password: 'x');
        $project = new Project($owner, 'Activity rename signal');
        $em->persist($owner);
        $em->persist($project);
        $em->flush();

        $this->service(WriteOutboxEventOnProjectRenamed::class)(new ProjectRenamed($project, 'old-slug', 'new-slug', ProjectEventType::ACTOR_HUMAN));
        $em->flush();

        self::assertNotNull(self::$kernel);
        $this->service(EventDispatcherInterface::class)->dispatch(new TerminateEvent(self::$kernel, Request::create('/'), new Response()), KernelEvents::TERMINATE);

        self::assertCount(1, $this->activitySignals($project));
    }

    /**
     * The drain queues its own signal, so it must run before the live updates go
     * out. A higher priority, because equal ones fall back to registration order.
     */
    public function test_the_outbox_drains_before_the_live_updates_publish(): void
    {
        $dispatcher = $this->service(EventDispatcherInterface::class);

        foreach ([KernelEvents::TERMINATE, ConsoleEvents::TERMINATE] as $eventName) {
            $order = [];
            $priorities = [];
            foreach ($dispatcher->getListeners($eventName) as $index => $listener) {
                if (\is_array($listener) && \is_object($listener[0])) {
                    $order[$listener[0]::class] ??= $index;
                    $priorities[$listener[0]::class] ??= $dispatcher->getListenerPriority($eventName, $listener);
                }
            }

            self::assertArrayHasKey(ImmediateOutboxPublisher::class, $order, $eventName);
            self::assertArrayHasKey(LiveUpdatePublisher::class, $order, $eventName);
            self::assertLessThan($order[LiveUpdatePublisher::class], $order[ImmediateOutboxPublisher::class], $eventName);
            self::assertGreaterThan($priorities[LiveUpdatePublisher::class], $priorities[ImmediateOutboxPublisher::class], $eventName);
        }
    }

    /** @return list<Update> */
    private function activitySignals(Project $project): array
    {
        $topic = $this->service(ProjectTopicBuilder::class)->forActivity($project->id ?? throw new \LogicException('The project has no id.'));

        return array_values(array_filter($this->published, static fn (Update $update): bool => [$topic] === $update->getTopics()));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $id = EventDispatcherInterface::class === $class ? 'event_dispatcher' : $class;
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
