<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardAutomationRepository;
use App\Module\Board\Service\CardPullRequestStates;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardPullRequestStatesTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private CardPullRequestStates $states;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $states = self::getContainer()->get(CardPullRequestStates::class);
        self::assertInstanceOf(CardPullRequestStates::class, $states);
        $this->states = $states;
    }

    public function test_every_link_to_a_stored_pull_request_reads_its_state(): void
    {
        $project = $this->makeProject('states');
        $projectId = $project->id ?? throw new \LogicException('The project is persisted.');
        $forgeRows = self::getContainer()->get(ForgePullRequestRepository::class);
        self::assertInstanceOf(ForgePullRequestRepository::class, $forgeRows);
        $forgeRows->insertIfMissing($projectId, 'github', 'ubermuda/loupe', 7);
        $first = $this->cardIn($project);
        $second = $this->cardIn($project);
        $firstLink = $this->link($first, 'Ubermuda/Loupe', 7);
        $secondLink = $this->link($second, 'ubermuda/loupe', 7);
        $untracked = $this->link($second, 'ubermuda/loupe', 8);
        $unparsed = $this->link($second, null, null);
        $automations = self::getContainer()->get(CardAutomationRepository::class);
        self::assertInstanceOf(CardAutomationRepository::class, $automations);
        $this->em->wrapInTransaction(static fn () => $automations->findOrCreateForUpdate($first));
        $this->em->flush();

        $states = $this->states->forCards([$first, $second]);

        self::assertSame(PullRequestChecks::Pending, $states->of($firstLink)?->checks);
        self::assertSame($states->of($firstLink), $states->of($secondLink));
        self::assertNull($states->of($untracked));
        self::assertNull($states->of($unparsed));
        self::assertNotNull($states->automationOf($first));
        self::assertNull($states->automationOf($second));
    }

    public function test_cards_of_two_projects_are_refused(): void
    {
        $cards = [$this->cardIn($this->makeProject('states-one')), $this->cardIn($this->makeProject('states-two'))];

        $this->expectException(\LogicException::class);
        $this->states->forCards($cards);
    }

    private function link(Card $card, ?string $repository, ?int $number): CardPullRequest
    {
        $link = new CardPullRequest($card, 'https://example.com/pull/'.uniqid(), null === $repository ? Forge::Other : Forge::GitHub, $repository, $number);
        $card->pullRequests->add($link);
        $this->em->persist($link);
        $this->em->flush();

        return $link;
    }

    private function cardIn(Project $project): Card
    {
        $handler = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $handler);

        return $handler(new CreateCardCommand($project, 'Ship it', 'Body', CardType::Feature));
    }
}
