<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Repository;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardPullRequestRepositoryTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_find_for_pull_request_ignores_the_case_of_the_repository(): void
    {
        $project = $this->makeProject('links-case');
        $card = new Card($project, $this->column($project, 'backlog'), 'Ship it', '', 1);
        $link = new CardPullRequest($card, 'https://github.com/Acme/Widgets/pull/5', Forge::GitHub, 'Acme/Widgets', 5);
        $this->em->persist($card);
        $this->em->persist($link);
        $this->em->flush();

        $links = self::getContainer()->get(CardPullRequestRepository::class);
        self::assertInstanceOf(CardPullRequestRepository::class, $links);

        self::assertSame([$link], $links->findForPullRequest($project->id ?? throw new \LogicException('Flushed.'), Forge::GitHub, 'acme/widgets', 5));
        self::assertSame([], $links->findForPullRequest($project->id, Forge::GitHub, 'acme/widgets', 6));
    }
}
