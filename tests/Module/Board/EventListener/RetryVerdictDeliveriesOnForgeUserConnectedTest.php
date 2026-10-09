<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardVerdict;
use App\Module\Board\Entity\CardVerdictDelivery;
use App\Module\Board\Entity\CardVerdictDeliveryState;
use App\Module\Board\Entity\CardVerdictKind;
use App\Module\Forge\Event\ForgeUserConnected;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Tests\Module\Board\CardVerdictScenario;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class RetryVerdictDeliveriesOnForgeUserConnectedTest extends KernelTestCase
{
    use BoardToolScenario;
    use CardVerdictScenario;

    private EntityManagerInterface $em;
    private Project $project;
    private User $reviewer;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->project = $this->makeProject('verdict-retry');
        $this->reviewer = $this->project->owner;
    }

    public function test_a_reconnect_sets_the_refused_deliveries_of_that_reviewer_back_to_pending_and_evaluates_each_card_once(): void
    {
        $first = $this->card($this->project);
        $second = $this->card($this->project, 'next', 2);
        $firstA = $this->refused($first, 7);
        $firstB = $this->refused($first, 8);
        $secondA = $this->refused($second, 9);
        $this->em->flush();

        $this->connect($this->reviewer);

        foreach ([$firstA, $firstB, $secondA] as $delivery) {
            $this->em->refresh($delivery);
            self::assertSame(CardVerdictDeliveryState::Pending, $delivery->state);
            self::assertNull($delivery->reason);
            self::assertNull($delivery->settledAt);
        }
        self::assertEqualsCanonicalizing(
            [new EvaluateCard((string) $first->id), new EvaluateCard((string) $second->id)],
            $this->evaluations(),
        );
    }

    public function test_it_leaves_every_other_delivery_alone(): void
    {
        $card = $this->card($this->project);
        $done = $this->card($this->project, 'done', 2);
        $someoneElse = new User(fullName: 'Sam', email: 'sam-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($someoneElse);

        $otherReason = $this->refused($card, 7, 'no-permission');
        $skipped = $this->refused($card, 8);
        $skipped->state = CardVerdictDeliveryState::Skipped;
        $theirs = $this->refused($card, 9, reviewer: $someoneElse);
        $onDoneCard = $this->refused($done, 10);
        $this->em->flush();

        $this->connect($this->reviewer);

        self::assertSame([], $this->evaluations());
        foreach ([[$otherReason, CardVerdictDeliveryState::Refused], [$skipped, CardVerdictDeliveryState::Skipped], [$theirs, CardVerdictDeliveryState::Refused], [$onDoneCard, CardVerdictDeliveryState::Refused]] as [$delivery, $state]) {
            $this->em->refresh($delivery);
            self::assertSame($state, $delivery->state);
        }
        self::assertSame('no-permission', $otherReason->reason);
        self::assertSame(CardVerdictDelivery::REASON_CONNECTION_EXPIRED, $onDoneCard->reason);
    }

    public function test_a_user_who_does_not_exist_changes_nothing(): void
    {
        $card = $this->card($this->project);
        $delivery = $this->refused($card, 7);
        $this->em->flush();

        self::getContainer()->get(EventDispatcherInterface::class)->dispatch(new ForgeUserConnected(Uuid::v7()));

        $this->em->refresh($delivery);
        self::assertSame(CardVerdictDeliveryState::Refused, $delivery->state);
        self::assertSame([], $this->evaluations());
    }

    private function refused(Card $card, int $number, string $reason = CardVerdictDelivery::REASON_CONNECTION_EXPIRED, ?User $reviewer = null): CardVerdictDelivery
    {
        $pullRequest = $this->linkedPullRequest($card, $number);
        $verdict = new CardVerdict($card, CardVerdictKind::Approve, $reviewer ?? $this->reviewer, '', []);
        $this->em->persist($verdict);
        $delivery = new CardVerdictDelivery($verdict, $pullRequest);
        $delivery->state = CardVerdictDeliveryState::Refused;
        $delivery->reason = $reason;
        $delivery->settledAt = new \DateTimeImmutable();
        $this->em->persist($delivery);

        return $delivery;
    }

    private function connect(User $user): void
    {
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch(new ForgeUserConnected($user->id ?? throw new \LogicException('A stored user has an id.')));
    }

    /** @return list<EvaluateCard> */
    private function evaluations(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()),
            static fn (object $message): bool => $message instanceof EvaluateCard,
        ));
    }
}
