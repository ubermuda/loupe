<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Bridge\View\CardTitleSourceInterface;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BoardCardTitleSourceTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private CardTitleSourceInterface $source;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $source = self::getContainer()->get(CardTitleSourceInterface::class);
        self::assertInstanceOf(CardTitleSourceInterface::class, $source);
        $this->source = $source;
    }

    public function test_it_returns_the_titles_of_the_projects_cards_only(): void
    {
        $project = $this->makeProject('card-titles');
        $other = $this->makeProject('card-titles-other');
        $first = $this->card($project, 1, 'Fix the login');
        $second = $this->card($project, 2, 'Add the export');
        $foreign = $this->card($other, 1, 'Another project');
        $this->em->clear();

        $titles = $this->source->titlesFor($project, [$first, $second, $foreign, Uuid::v7()]);

        $expected = [(string) $first => 'Fix the login', (string) $second => 'Add the export'];
        ksort($expected);
        ksort($titles);
        self::assertSame($expected, $titles);
    }

    public function test_it_returns_nothing_for_no_ids(): void
    {
        self::assertSame([], $this->source->titlesFor($this->makeProject('card-titles-empty'), []));
    }

    private function card(Project $project, int $number, string $title): Uuid
    {
        $card = new Card(project: $project, column: $this->column($project, 'backlog'), title: $title, body: '', number: $number);
        $this->em->persist($card);
        $this->em->flush();

        return $card->id ?? throw new \LogicException('The card has no id after a flush.');
    }
}
