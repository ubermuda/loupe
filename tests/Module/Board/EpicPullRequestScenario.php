<?php

declare(strict_types=1);

namespace App\Tests\Module\Board;

use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Entity\Forge;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Symfony\Component\Uid\Uuid;

/**
 * KernelTestCase helper for the writes to an epic's pull request.
 * Requires an `$em` EntityManagerInterface property on the using class.
 */
trait EpicPullRequestScenario
{
    use BoardToolScenario;

    private int $cardNumber = 0;

    /** A board with open `implementation` and `in-review` columns. */
    private function reviewProject(string $label): Project
    {
        $project = $this->makeProject($label);
        $this->em->persist(new BoardColumn($project, 'Implementation', 'implementation', 4));
        $this->em->persist(new BoardColumn($project, 'In review', 'in-review', 5));
        $this->em->flush();

        return $project;
    }

    private function card(Project $project, string $column, CardType $type = CardType::Epic): Card
    {
        $card = new Card($project, $this->column($project, $column), 'Card', '', ++$this->cardNumber, $type);
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }

    /** A false $tracked links a pull request that Forge has no row for. */
    private function linkPullRequest(Card $card, int $number, bool $tracked = true, string $repository = 'Acme/Widgets'): ForgePullRequest
    {
        $this->em->persist(new CardPullRequest($card, 'https://github.com/'.$repository.'/pull/'.$number, Forge::GitHub, $repository, $number));
        $row = new ForgePullRequest($card->project, 'github', $repository, $number);
        if ($tracked) {
            $this->em->persist($row);
        }
        $this->em->flush();

        return $row;
    }

    private function moveTo(Card $card, string $column, CardReporter $actor = CardReporter::Human, ?int $position = null): void
    {
        $update = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $update);
        $update(new UpdateCardCommand($card, $actor, column: $this->column($card->project, $column), position: $position));
    }

    private function cardId(Card $card): Uuid
    {
        return $card->id ?? throw new \LogicException('A flushed card has an id.');
    }
}
