<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Repository;

use App\Module\Board\Entity\Card;
use App\Tests\Module\Board\CardPauseScenario;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardPauseRepositoryTest extends KernelTestCase
{
    use BoardToolScenario;
    use CardPauseScenario;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_find_active_for_card_ids_keys_the_active_pauses_by_card_id(): void
    {
        $project = $this->makeProject('pause-find');
        $paused = $this->cardIn($project);
        $released = $this->cardIn($project);
        $neverPaused = $this->cardIn($project);
        $elsewhere = $this->cardIn($this->makeProject('pause-find-other'));
        $active = $this->pause($paused);
        $this->pause($released)?->release('owner-resumed', new \DateTimeImmutable());
        $this->pause($elsewhere);
        $this->em->flush();

        $pauses = $this->pauseRepository()->findActiveForCardIds([
            $this->id($paused),
            (string) $this->id($released),
            $this->id($neverPaused),
        ]);

        self::assertSame([(string) $paused->id], array_keys($pauses));
        self::assertSame($active, $pauses[(string) $paused->id]);
        self::assertSame([], $this->pauseRepository()->findActiveForCardIds([]));
    }

    public function test_deleting_a_card_deletes_its_pauses(): void
    {
        $card = $this->cardIn($this->makeProject('pause-cascade'));
        $this->pause($card);
        self::assertSame(1, $this->countPauses());

        $this->em->createQuery('DELETE '.Card::class.' c WHERE c.id = :id')
            ->setParameter('id', $card->id, 'uuid')
            ->execute();

        self::assertSame(0, $this->countPauses());
    }

    private function id(Card $card): \Symfony\Component\Uid\Uuid
    {
        return $card->id ?? throw new \LogicException('A persisted card has an id.');
    }
}
