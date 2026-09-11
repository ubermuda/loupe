<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Doctrine\SearchLanguage;
use App\Module\Board\Command\SearchBoardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Mcp\CardCreateTool;
use App\Module\Board\Mcp\CardSearchTool;
use App\Module\Board\Mcp\CardUpdateTool;
use App\Module\Board\Repository\CardRepository;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardSearchToolTest extends KernelTestCase
{
    use BoardToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private CardSearchTool $tool;
    private CardCreateTool $createTool;
    private CardUpdateTool $updateTool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(CardSearchTool::class);
        self::assertInstanceOf(CardSearchTool::class, $tool);
        $this->tool = $tool;

        $createTool = self::getContainer()->get(CardCreateTool::class);
        self::assertInstanceOf(CardCreateTool::class, $createTool);
        $this->createTool = $createTool;

        $updateTool = self::getContainer()->get(CardUpdateTool::class);
        self::assertInstanceOf(CardUpdateTool::class, $updateTool);
        $this->updateTool = $updateTool;
    }

    public function test_the_tool_refuses_while_the_flag_is_off(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('card-search-flag-off'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The board is switched off on this instance.');
        ($this->tool)('anything');
    }

    public function test_a_blank_query_is_refused_rather_than_read_as_an_empty_board(): void
    {
        $this->boardWith('card-search-blank');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Pass a query to search for.');
        ($this->tool)('   ');
    }

    public function test_a_title_word_finds_the_card(): void
    {
        $this->boardWith('card-search-title');

        $result = ($this->tool)('mailpit');

        self::assertSame(1, $result['total']);
        self::assertSame('Give each worktree its own Mailpit', $result['cards'][0]['title']);
    }

    /** The point of indexing the body: a topic is often named only there. */
    public function test_a_word_that_lives_only_in_a_body_finds_the_card(): void
    {
        $this->boardWith('card-search-body');

        $result = ($this->tool)('kestrel');

        self::assertSame(1, $result['total']);
        // The word is in this card's body and in no card's title, so a
        // title-only index answers nothing here.
        self::assertSame('Rotate the signing key', $result['cards'][0]['title']);
        self::assertStringNotContainsStringIgnoringCase('kestrel', $result['cards'][0]['title']);
    }

    /** The other half of the decision: "yes, and it is already done" is a true answer. */
    public function test_a_done_card_is_returned(): void
    {
        $this->boardWith('card-search-done');

        $result = ($this->tool)('hedgehog');

        self::assertSame(1, $result['total']);
        self::assertSame('done', $result['cards'][0]['status']);
        self::assertSame('Footer was fixed once already', $result['cards'][0]['title']);
    }

    public function test_words_are_stemmed_rather_than_matched_as_substrings(): void
    {
        $this->boardWith('card-search-stemming');

        // "paginate" and "pagination" share a stem; "pag" is a substring of both
        // and a word of neither.
        self::assertSame(1, ($this->tool)('paginate')['total']);
        self::assertSame(0, ($this->tool)('pag')['total']);
    }

    public function test_a_title_match_outranks_a_body_match(): void
    {
        $this->boardWith('card-search-rank');
        ($this->createTool)('Named in the body only', 'The gannet is mentioned here.', 'docs', 'low');
        ($this->createTool)('Gannet tooling', 'Unrelated text.', 'tooling', 'low');

        $titles = array_column(($this->tool)('gannet')['cards'], 'title');

        self::assertSame(['Gannet tooling', 'Named in the body only'], $titles);
    }

    public function test_an_edited_body_is_reindexed(): void
    {
        $this->boardWith('card-search-reindex');
        $card = ($this->createTool)('Nothing special', 'The body says albatross.', 'feature', 'low');
        // Guard: without this the assertions below also pass on a card that was
        // never indexed at all.
        self::assertSame(1, ($this->tool)('albatross')['total']);

        ($this->updateTool)($card['cardId'], body: 'The body now says narwhal.');

        self::assertSame(0, ($this->tool)('albatross')['total']);
        self::assertSame(1, ($this->tool)('narwhal')['total']);
    }

    public function test_an_edited_title_is_reindexed(): void
    {
        $this->boardWith('card-search-retitle');
        $card = ($this->createTool)('Wombat handling', 'Body.', 'feature', 'low');
        self::assertSame(1, ($this->tool)('wombat')['total']);

        ($this->updateTool)($card['cardId'], title: 'Capybara handling');

        self::assertSame(0, ($this->tool)('wombat')['total']);
        self::assertSame(1, ($this->tool)('capybara')['total']);
    }

    public function test_another_projects_cards_are_not_found(): void
    {
        $this->boardWith('card-search-mine');
        $this->actAsMcpTokenBoundTo($this->makeProject('card-search-theirs'));

        self::assertSame(0, ($this->tool)('mailpit')['total']);
    }

    public function test_a_row_is_the_same_summary_card_list_returns(): void
    {
        $this->boardWith('card-search-summary');

        $row = ($this->tool)('mailpit')['cards'][0];

        self::assertSame(
            ['cardId', 'number', 'title', 'type', 'priority', 'status', 'origin', 'updatedAt'],
            array_keys($row),
        );
    }

    public function test_a_page_is_cut_from_the_matches_and_total_counts_them_all(): void
    {
        $this->boardWith('card-search-paging');
        ($this->createTool)('Otter one', 'Body.', 'feature', 'low');
        ($this->createTool)('Otter two', 'Body.', 'feature', 'low');
        ($this->createTool)('Otter three', 'Body.', 'feature', 'low');

        $first = ($this->tool)('otter', perPage: 2);
        $second = ($this->tool)('otter', page: 2, perPage: 2);

        self::assertCount(2, $first['cards']);
        self::assertSame(3, $first['total']);
        self::assertTrue($first['hasMore']);

        self::assertCount(1, $second['cards']);
        self::assertSame(3, $second['total']);
        self::assertFalse($second['hasMore']);
    }

    public function test_a_page_past_the_end_is_empty_rather_than_an_error(): void
    {
        $this->boardWith('card-search-overrun');

        $result = ($this->tool)('mailpit', page: 99);

        self::assertSame([], $result['cards']);
        self::assertSame(1, $result['total']);
        self::assertFalse($result['hasMore']);
    }

    public function test_the_largest_page_number_reads_empty_rather_than_overflowing(): void
    {
        $this->boardWith('card-search-overflow');

        $result = ($this->tool)('mailpit', page: \PHP_INT_MAX);

        self::assertSame([], $result['cards']);
        self::assertSame(1, $result['total']);
    }

    public function test_page_and_per_page_are_clamped_rather_than_refused(): void
    {
        $this->boardWith('card-search-clamp');

        self::assertSame(1, ($this->tool)('mailpit', page: -4)['page']);
        self::assertSame(1, ($this->tool)('mailpit', perPage: 0)['perPage']);
        self::assertSame(SearchBoardHandler::MAX_PER_PAGE, ($this->tool)('mailpit', perPage: 500)['perPage']);
    }

    /**
     * The project's language is the card's, read once at creation. Without it a
     * French project's cards are stemmed as English while the query that reads
     * them is parsed as French, and the two never meet.
     */
    public function test_a_card_takes_its_projects_search_language(): void
    {
        $this->enableBoard();
        $project = $this->makeProject('card-search-language');
        $project->searchLanguage = SearchLanguage::French;
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        $created = ($this->createTool)('Les cartes du tableau', 'Le corps parle de goeland.', 'feature', 'low');

        $cards = self::getContainer()->get(CardRepository::class);
        self::assertInstanceOf(CardRepository::class, $cards);
        $card = $cards->find($created['cardId']);
        self::assertInstanceOf(Card::class, $card);
        self::assertSame(SearchLanguage::French, $card->searchLanguage);
        self::assertSame([SearchLanguage::French], $cards->searchLanguagesOf($project));

        // The vector and the query are both built as French, so the card is
        // still findable rather than indexed under a configuration nothing asks
        // for.
        self::assertSame(1, ($this->tool)('goeland')['total']);
    }

    private function boardWith(string $label): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject($label));

        ($this->createTool)('Give each worktree its own Mailpit', 'Two runs read one inbox.', 'tooling', 'high');
        ($this->createTool)('Rotate the signing key', 'The kestrel host holds it.', 'security', 'high');
        ($this->createTool)('Paginate the board', 'One page at a time.', 'feature', 'medium');
        ($this->createTool)('Footer was fixed once already', 'A hedgehog sat on it.', 'bug', 'low', status: 'done');
    }
}
