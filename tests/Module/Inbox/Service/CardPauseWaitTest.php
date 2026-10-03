<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Command\ReleaseCardPauseCommand;
use App\Module\Board\Command\ReleaseCardPauseHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitEndReason;
use App\Module\Inbox\Entity\InboxCardWaitTrigger;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxProjectSettings;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Messenger\ReconcileCardWaits;
use App\Module\Inbox\Messenger\ReconcileCardWaitsHandler;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\Service\CardWaitReconciler;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Event\CardPaused;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/** A workflow pause opens a wait, and its release ends it, through the real events and the queued reconcile. */
final class CardPauseWaitTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private Project $project;
    private Card $card;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->project = $this->project($em, $this->owner($em, 'pause-waits'), 'pause-waits');
        $this->card = $this->card($em, $this->project, 7);
        $em->flush();
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
        $this->transport()->reset();
    }

    public function test_a_paused_card_waits_and_the_release_ends_the_wait(): void
    {
        $pause = $this->pause('owner-review');

        $wait = $this->onlyWait($this->onlyWatch());
        self::assertSame(InboxCardWaitTrigger::CardPaused, $wait->trigger);
        self::assertSame('Workflow paused: owner-review', $wait->reason);
        self::assertEquals($pause->id, $wait->pauseId);
        self::assertSame(InboxItemState::Open, $this->onlyWatch()->item->state);

        $this->release($pause);

        $watch = $this->onlyWatch();
        self::assertSame(InboxItemState::Done, $watch->item->state);
        self::assertSame(InboxCardWaitEndReason::Resolved, $this->onlyWait($watch)->endReason);
    }

    public function test_a_new_pause_after_a_release_opens_a_new_wait(): void
    {
        $this->release($this->pause('owner-review'));

        $second = $this->pause('owner-review');

        $watches = $this->watches();
        self::assertCount(2, $watches);
        self::assertSame(InboxItemState::Open, $watches[1]->item->state);
        self::assertEquals($second->id, $this->onlyWait($watches[1])->pauseId);
    }

    public function test_the_switch_off_opens_no_wait(): void
    {
        $settings = new InboxProjectSettings($this->project);
        $settings->cardPaused = false;
        $this->em->persist($settings);
        $this->em->flush();

        $this->pause('owner-review');

        self::assertSame([], $this->watches());
    }

    public function test_the_project_sweep_finds_a_card_that_only_has_a_pause(): void
    {
        $this->pauseHandler()(new PauseCardCommand($this->card, 'owner-review', 'review-rule', CardPauseKind::Rule));
        self::assertSame([], $this->watches());

        $reconciler = self::getContainer()->get(CardWaitReconciler::class);
        self::assertInstanceOf(CardWaitReconciler::class, $reconciler);
        $reconciler->reconcile($this->project, null);

        self::assertSame(InboxCardWaitTrigger::CardPaused, $this->onlyWait($this->onlyWatch())->trigger);
    }

    /** Pauses the card as the engine does, with CardPaused after the commit, then runs the queued reconciles. */
    private function pause(string $reason): CardPause
    {
        $card = $this->em->find(Card::class, $this->cardId()) ?? throw new \LogicException('The card is stored.');
        $pause = $this->pauseHandler()(new PauseCardCommand($card, $reason, 'review-rule', CardPauseKind::Rule));
        self::assertNotNull($pause);
        $this->transport()->reset();
        $events = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        $events->dispatch(new CardPaused($this->projectId(), $this->cardId(), $pause->reason, $pause->kind));
        $this->drain();

        return $pause;
    }

    private function release(CardPause $pause): void
    {
        $handler = self::getContainer()->get(ReleaseCardPauseHandler::class);
        self::assertInstanceOf(ReleaseCardPauseHandler::class, $handler);
        $managed = $this->em->find(CardPause::class, $pause->id) ?? throw new \LogicException('The pause is stored.');
        self::assertTrue($handler(new ReleaseCardPauseCommand($managed, 'until-met')));
        $this->drain();
    }

    private function pauseHandler(): PauseCardHandler
    {
        $handler = self::getContainer()->get(PauseCardHandler::class);
        self::assertInstanceOf(PauseCardHandler::class, $handler);

        return $handler;
    }

    /** Runs each queued reconcile, and fails when none was queued. */
    private function drain(): void
    {
        $handler = self::getContainer()->get(ReconcileCardWaitsHandler::class);
        self::assertInstanceOf(ReconcileCardWaitsHandler::class, $handler);
        $messages = array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $this->transport()->getSent()),
            static fn (object $message): bool => $message instanceof ReconcileCardWaits,
        ));
        self::assertNotEmpty($messages);
        $this->transport()->reset();
        foreach ($messages as $message) {
            $handler($message);
        }
        $this->em->clear();
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /** @return list<InboxCardWatch> in item number order */
    private function watches(): array
    {
        $repository = self::getContainer()->get(InboxCardWatchRepository::class);
        self::assertInstanceOf(InboxCardWatchRepository::class, $repository);
        $watches = $repository->findBy(['cardId' => $this->card->id]);
        usort($watches, static fn (InboxCardWatch $a, InboxCardWatch $b): int => $a->item->number <=> $b->item->number);

        return $watches;
    }

    private function onlyWatch(): InboxCardWatch
    {
        $watches = $this->watches();
        self::assertCount(1, $watches);

        return $watches[0];
    }

    private function onlyWait(InboxCardWatch $watch): InboxCardWait
    {
        self::assertCount(1, $watch->waits);
        $wait = $watch->waits->first();
        self::assertInstanceOf(InboxCardWait::class, $wait);

        return $wait;
    }

    private function projectId(): Uuid
    {
        return $this->project->id ?? throw new \LogicException('Project has no id.');
    }

    private function cardId(): Uuid
    {
        return $this->card->id ?? throw new \LogicException('Card has no id.');
    }
}
