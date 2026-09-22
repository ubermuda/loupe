<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Repository;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardRepositoryTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private CardRepository $cards;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $cards = self::getContainer()->get(CardRepository::class);
        self::assertInstanceOf(CardRepository::class, $cards);
        $this->cards = $cards;
    }

    private function cardIn(Project $project): Card
    {
        $handler = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $handler);

        return $handler(new CreateCardCommand($project, 'Ship it', 'Body', CardType::Feature));
    }

    public function test_a_number_resolves_inside_its_own_project_only(): void
    {
        $mine = $this->makeProject('repo-number-mine');
        $theirs = $this->makeProject('repo-number-theirs');
        $theirCard = $this->cardIn($theirs);
        $myCard = $this->cardIn($mine);

        // Guard: both projects hold a card 1, so a lookup that ignores the project could match either.
        self::assertSame(1, $myCard->number);
        self::assertSame(1, $theirCard->number);

        self::assertSame($myCard, $this->cards->findOneByProjectAndNumber($mine, 1));
        self::assertSame($theirCard, $this->cards->findOneByProjectAndNumber($theirs, 1));
        self::assertNull($this->cards->findOneByProjectAndNumber($mine, 2));
    }
}
