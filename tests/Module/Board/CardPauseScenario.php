<?php

declare(strict_types=1);

namespace App\Tests\Module\Board;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Command\ReleaseCardPauseHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Support\RecordingAuditor;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\MockClock;
use Ubermuda\AuditBundle\AuditActorProviderInterface;
use Ubermuda\AuditBundle\Auditor;

/**
 * Builds the pause handlers by hand, because nothing in production calls them
 * yet and the compiled container holds none. Requires an `$em` property.
 */
trait CardPauseScenario
{
    private function pauseHandler(Auditor $auditor, string $now = '2026-10-02 10:00:00'): PauseCardHandler
    {
        return new PauseCardHandler($this->pauseRepository(), $this->em, new MockClock($now), $auditor, $this->dispatcher(), $this->cardEventRepository());
    }

    private function releaseHandler(Auditor $auditor, string $now = '2026-10-02 11:00:00'): ReleaseCardPauseHandler
    {
        return new ReleaseCardPauseHandler($this->em, new MockClock($now), $auditor, $this->dispatcher());
    }

    private function pauseRepository(): CardPauseRepository
    {
        $repository = self::getContainer()->get(CardPauseRepository::class);
        self::assertInstanceOf(CardPauseRepository::class, $repository);

        return $repository;
    }

    private function cardEventRepository(): CardEventRepository
    {
        $repository = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $repository);

        return $repository;
    }

    private function recordingAuditor(): RecordingAuditor
    {
        $actors = self::getContainer()->get(AuditActorProviderInterface::class);
        self::assertInstanceOf(AuditActorProviderInterface::class, $actors);

        return new RecordingAuditor($actors);
    }

    private function silentAuditor(): Auditor
    {
        $auditor = self::getContainer()->get(Auditor::class);
        self::assertInstanceOf(Auditor::class, $auditor);

        return $auditor;
    }

    private function pause(Card $card, string $reason = 'review-failed', string $ruleId = 'fix-on-review', CardPauseKind $kind = CardPauseKind::Rule): ?CardPause
    {
        return $this->pauseHandler($this->silentAuditor())(new PauseCardCommand($card, $reason, $ruleId, $kind));
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    private function cardIn(Project $project): Card
    {
        $handler = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $handler);

        return $handler(new CreateCardCommand($project, 'Ship it', 'Body', 'feature'));
    }

    private function countPauses(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM card_pauses');
    }
}
