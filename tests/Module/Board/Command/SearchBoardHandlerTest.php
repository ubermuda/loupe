<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\SearchBoardCommand;
use App\Module\Board\Command\SearchBoardHandler;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Entity\CardType;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The handler owns the search rule, so it answers the same way with no MCP tool
 * in front of it.
 */
final class SearchBoardHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SearchBoardHandler $searchBoard;
    private CreateCardHandler $createCard;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $searchBoard = self::getContainer()->get(SearchBoardHandler::class);
        self::assertInstanceOf(SearchBoardHandler::class, $searchBoard);
        $this->searchBoard = $searchBoard;

        $createCard = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $createCard);
        $this->createCard = $createCard;

        $owner = new User(fullName: 'Riley', email: 'search-handler-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'board-'.uniqid());
        $this->em->persist($this->project);
        $this->em->flush();
    }

    public function test_a_blank_query_raises_a_domain_error_rather_than_an_empty_page(): void
    {
        $this->card('Give each worktree its own Mailpit', 'Two runs read one inbox.');

        try {
            ($this->searchBoard)(new SearchBoardCommand($this->project, '   ', 1, 25));
            self::fail('Expected DomainErrors.');
        } catch (DomainErrors $e) {
            self::assertSame(['query' => 'board.card.error.search_query_blank'], $e->errors);
        }
    }

    public function test_the_handler_trims_the_query(): void
    {
        $this->card('Give each worktree its own Mailpit', 'Two runs read one inbox.');

        $view = ($this->searchBoard)(new SearchBoardCommand($this->project, "  mailpit\n", 1, 25));

        self::assertSame(1, $view->total);
    }

    public function test_the_view_reports_the_clamped_paging_rather_than_what_was_asked_for(): void
    {
        $this->card('Otter one', 'Body.');
        $this->card('Otter two', 'Body.');

        $tooSmall = ($this->searchBoard)(new SearchBoardCommand($this->project, 'otter', -4, 0));
        self::assertSame(1, $tooSmall->page);
        self::assertSame(1, $tooSmall->perPage);
        self::assertTrue($tooSmall->hasMore);

        $tooLarge = ($this->searchBoard)(new SearchBoardCommand($this->project, 'otter', 1, 500));
        self::assertSame(SearchBoardHandler::MAX_PER_PAGE, $tooLarge->perPage);
        self::assertFalse($tooLarge->hasMore);
    }

    /** Without the clamp the offset multiplication overflows to a float, which setFirstResult() refuses. */
    public function test_the_largest_page_number_reads_empty_rather_than_overflowing(): void
    {
        $this->card('Otter one', 'Body.');

        $view = ($this->searchBoard)(new SearchBoardCommand($this->project, 'otter', \PHP_INT_MAX, 25));

        self::assertSame([], $view->cards);
        self::assertSame(1, $view->total);
        self::assertFalse($view->hasMore);
    }

    private function card(string $title, string $body): void
    {
        ($this->createCard)(new CreateCardCommand(
            project: $this->project,
            title: $title,
            body: $body,
            type: CardType::Feature,
            priority: CardPriority::Medium,
            status: CardStatus::Backlog,
        ));
    }
}
