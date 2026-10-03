<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Command;

use App\Mercure\UserTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Service\EventStreamGate;
use App\Module\Bridge\Service\HeartbeatInterval;
use App\Module\Bridge\Service\StopLadder;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\SiteReview\Command\ShowEventsCommand;
use App\Module\SiteReview\Command\ShowEventsHandler;
use App\Outbox\Repository\OutboxEventRepository;
use App\Tests\Support\FeatureFlags;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * An instance with no hub sets no MERCURE_JWT_SECRET, and the Mercure JWT
 * factory reads it while it is built. Injected directly, the container cannot
 * build this handler, so it cannot build the controller that holds it, and
 * GET /api/events answers 500 from ControllerResolver before
 * RequireFeatureFlag can answer the 404 it is there to answer.
 *
 * Production ran exactly that on 2026-09-22.
 */
final class ShowEventsHandlerLazinessTest extends TestCase
{
    public function test_it_does_not_build_the_token_factory_until_it_signs(): void
    {
        $built = false;
        $user = new User(fullName: 'Riley Chen', email: 'riley@example.com', password: 'x');
        $bridge = new Bridge($user, Uuid::v4(), [], '1.0.0', new \DateTimeImmutable());
        $bridge->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS];
        $bridges = $this->createStub(BridgeRepository::class);
        $bridges->method('findOneByOwnerAndId')->willReturn($bridge);

        $handler = new ShowEventsHandler(
            $this->createStub(ProjectRepository::class),
            $this->createStub(OutboxEventRepository::class),
            new UserTopicBuilder('https://loupe.example.com'),
            FeatureFlags::service(),
            new HeartbeatInterval(FeatureFlags::service(), 30),
            new StopLadder(FeatureFlags::service(), 7500, 2500),
            new EventStreamGate($bridges, new NullLogger()),
            static function () use (&$built): never {
                $built = true;

                throw new \RuntimeException('the token factory was built while the handler was constructed');
            },
            'https://hub.example.com/.well-known/mercure',
        );

        self::assertFalse($built, 'the handler built the token factory while it was constructed');

        // Reaching the handler at all proves the closure is what was injected:
        // a user with no id fails before any signing, so the factory stays
        // unbuilt on this path too.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('User has no id.');
        $handler(new ShowEventsCommand($user, (string) $bridge->id));
    }
}
