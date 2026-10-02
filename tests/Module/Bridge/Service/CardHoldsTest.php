<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Entity\CardHold;
use App\Module\Bridge\Repository\CardHoldRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class CardHoldsTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-09-29 12:00:00';

    public function test_hold_creates_a_hold_with_its_holder(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'hold-create@example.com');
        $project = $this->project($em, $owner, 'Held Project');
        $cardId = Uuid::v7();

        $hold = $this->holds()->hold($project, $cardId, $owner);

        $em->clear();
        $stored = $em->find(CardHold::class, $hold->id);
        self::assertNotNull($stored);
        self::assertSame((string) $project->id, (string) $stored->project->id);
        self::assertTrue($cardId->equals($stored->cardId));
        self::assertSame((string) $owner->id, (string) $stored->heldBy?->id);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $stored->heldAt);
    }

    public function test_hold_again_returns_the_same_hold(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'hold-twice@example.com');
        $project = $this->project($em, $owner, 'Held Twice');
        $cardId = Uuid::v7();

        $first = $this->holds()->hold($project, $cardId, $owner);
        $em->clear();
        $project = $this->reload($project);
        $second = $this->holds()->hold($project, $cardId, null);

        self::assertSame((string) $first->id, (string) $second->id);
        self::assertSame((string) $owner->id, (string) $second->heldBy?->id);
        self::assertSame(1, $this->countHolds());
    }

    public function test_release_removes_only_the_named_cards_of_that_project(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'hold-release@example.com');
        $project = $this->project($em, $owner, 'Released Project');
        $other = $this->project($em, $owner, 'Other Project');
        $released = Uuid::v7();
        $kept = Uuid::v7();
        $this->holds()->hold($project, $released, null);
        $this->holds()->hold($project, $kept, null);
        $this->holds()->hold($other, $released, null);

        self::assertSame(1, $this->holds()->release($project, [$released, Uuid::v7()]));

        self::assertFalse($this->holds()->isHeld($project, $released));
        self::assertTrue($this->holds()->isHeld($project, $kept));
        self::assertTrue($this->holds()->isHeld($other, $released));
    }

    public function test_release_of_no_cards_removes_nothing(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'hold-release-none@example.com'), 'Nothing Released');
        $this->holds()->hold($project, Uuid::v7(), null);

        self::assertSame(0, $this->holds()->release($project, []));
        self::assertSame(1, $this->countHolds());
    }

    public function test_a_card_with_no_hold_is_not_held(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'hold-none@example.com'), 'No Hold');

        self::assertFalse($this->holds()->isHeld($project, Uuid::v7()));
    }

    public function test_a_card_can_be_held_again_after_a_release(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'hold-again@example.com'), 'Held Again');
        $cardId = Uuid::v7();
        $first = $this->holds()->hold($project, $cardId, null);
        $this->holds()->release($project, [$cardId]);

        $second = $this->holds()->hold($project, $cardId, null);

        self::assertNotSame((string) $first->id, (string) $second->id);
        self::assertTrue($this->holds()->isHeld($project, $cardId));
    }

    private function holds(): CardHolds
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return new CardHolds(new CardHoldRepository($registry), $this->em(), new MockClock(new \DateTimeImmutable(self::NOW)));
    }

    private function reload(Project $project): Project
    {
        return $this->em()->find(Project::class, $project->id) ?? throw new \LogicException('The project is gone.');
    }

    private function countHolds(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_card_holds');
    }
}
