<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Repository;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardAutomation;
use App\Module\Board\Entity\CardAutomationAction;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\CardAutomationRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardAutomationRepositoryTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private CardAutomationRepository $automations;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $automations = self::getContainer()->get(CardAutomationRepository::class);
        self::assertInstanceOf(CardAutomationRepository::class, $automations);
        $this->automations = $automations;
    }

    public function test_find_or_create_for_update_creates_a_row_with_no_rounds(): void
    {
        $card = $this->cardIn($this->makeProject('automation-create'));
        self::assertSame(0, $this->rowCount($card));

        $automation = $this->lockedRow($card);

        self::assertSame(1, $this->rowCount($card));
        self::assertSame((string) $card->id, (string) $automation->card->id);
        self::assertSame(0, $automation->fixRounds);
        self::assertNull($automation->blockedReason);
        self::assertNull($automation->lastAction);
        self::assertNull($automation->lastActionAt);
    }

    public function test_find_or_create_for_update_reuses_the_row_and_reads_it_fresh(): void
    {
        $card = $this->cardIn($this->makeProject('automation-reuse'));
        $first = $this->lockedRow($card);
        $first->fixRounds = 2;
        $first->lastAction = CardAutomationAction::FixRequested;
        $this->em->flush();

        $this->em->getConnection()->executeStatement(
            'UPDATE board_card_automations SET fix_rounds = 5 WHERE card_id = :card',
            ['card' => (string) $card->id],
        );
        $second = $this->lockedRow($card);

        self::assertSame($first, $second);
        self::assertSame(5, $second->fixRounds);
        self::assertSame(CardAutomationAction::FixRequested, $second->lastAction);
        self::assertSame(1, $this->rowCount($card));
    }

    public function test_deleting_a_card_deletes_its_automation_row(): void
    {
        $card = $this->cardIn($this->makeProject('automation-cascade'));
        $this->lockedRow($card);
        $this->em->flush();
        self::assertSame(1, $this->rowCount($card));

        $this->em->createQuery('DELETE '.Card::class.' c WHERE c.id = :id')
            ->setParameter('id', $card->id, 'uuid')
            ->execute();

        self::assertSame(0, $this->rowCount($card));
    }

    private function lockedRow(Card $card): CardAutomation
    {
        return $this->em->wrapInTransaction(fn (): CardAutomation => $this->automations->findOrCreateForUpdate($card));
    }

    private function rowCount(Card $card): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM board_card_automations WHERE card_id = :card',
            ['card' => (string) $card->id],
        );
    }

    private function cardIn(Project $project): Card
    {
        $handler = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $handler);

        return $handler(new CreateCardCommand($project, 'Ship it', 'Body', CardType::Feature));
    }
}
