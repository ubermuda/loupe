<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\LiveUpdates;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Module\Bridge\Service\WorkRequestAnnouncer;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\FeatureFlags;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

final class WorkRequestAnnouncerTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_each_request_is_an_event_and_each_project_reloads_its_run_pages_once(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'announcer@example.com');
        $first = $this->project($em, $owner, 'Announcer First');
        $second = $this->project($em, $owner, 'Announcer Second');
        $a = $this->seedWorkRequest($em, $first);
        $b = $this->seedWorkRequest($em, $first, state: WorkRequestState::Claimed);
        $c = $this->seedWorkRequest($em, $second, state: WorkRequestState::Done);

        $events = new EventDispatcher();
        $dispatched = [];
        $events->addListener(WorkRequestChanged::class, static function (WorkRequestChanged $event) use (&$dispatched): void {
            $dispatched[] = $event;
        });
        $published = [];
        $live = new LiveUpdatePublisher(
            new RequestStack(),
            FeatureFlags::service([LiveUpdates::FLAG => true]),
            new NullLogger(),
            static function () use (&$published): HubInterface {
                return new MockHub('http://mercure/.well-known/mercure', new StaticTokenProvider('token'), static function (Update $update) use (&$published): string {
                    $published[] = $update;

                    return 'id';
                });
            },
        );
        $topics = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);

        new WorkRequestAnnouncer($events, new WorkerRunChangedPublisher($topics, $live))->announce($a, $b, $c);
        $live->publish();

        self::assertEquals([
            new WorkRequestChanged($first->id ?? throw new \LogicException(), WorkSubject::CARD, $a->subjectId, $a->id ?? throw new \LogicException(), WorkRequestState::Open),
            new WorkRequestChanged($first->id, WorkSubject::CARD, $b->subjectId, $b->id ?? throw new \LogicException(), WorkRequestState::Claimed),
            new WorkRequestChanged($second->id ?? throw new \LogicException(), WorkSubject::CARD, $c->subjectId, $c->id ?? throw new \LogicException(), WorkRequestState::Done),
        ], $dispatched);
        self::assertSame(
            [$topics->forWorkerRuns($first->id), $topics->forWorkerRuns($second->id)],
            array_map(static fn (Update $update): string => $update->getTopics()[0], $published),
        );
    }

    public function test_no_request_announces_nothing(): void
    {
        $events = new EventDispatcher();
        $dispatched = 0;
        $events->addListener(WorkRequestChanged::class, static function () use (&$dispatched): void {
            ++$dispatched;
        });
        $live = new LiveUpdatePublisher(new RequestStack(), FeatureFlags::service(), new NullLogger(), fn (): HubInterface => $this->createStub(HubInterface::class));

        new WorkRequestAnnouncer($events, new WorkerRunChangedPublisher(new ProjectTopicBuilder('https://loupe.test'), $live))->announce();

        self::assertSame(0, $dispatched);
    }
}
