<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Form\DeleteBoardColumnFormType;
use App\Module\Board\Form\RenameBoardColumnFormType;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class BoardColumnControllersTest extends WebTestCase
{
    use BoardScenario;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->enableBoard();
    }

    public function test_the_owner_sees_the_column_controls(): void
    {
        [, $project] = $this->ownedBoard('columns-controls@example.com');

        $crawler = $this->board($project);

        self::assertCount(4, $crawler->filter('.lp-board__column-menu'));
        self::assertCount(1, $crawler->filter('form[action$="/board/columns"]'));
        self::assertCount(4, $crawler->filter('[data-board-columns-target="column"] [draggable="true"]'));
    }

    public function test_the_owner_adds_a_column_from_the_board(): void
    {
        [, $project] = $this->ownedBoard('columns-add@example.com');
        $crawler = $this->board($project);

        $this->client->submit($crawler->filter('form[action$="/board/columns"]')->form(['add_board_column_form[label]' => 'Parked']));

        self::assertResponseRedirects('/projects/'.$project->id.'/board');
        self::assertSame(['backlog', 'next', 'in-progress', 'done', 'parked'], $this->slugs($project));
    }

    public function test_a_refused_add_renders_the_board_with_the_error(): void
    {
        [, $project] = $this->ownedBoard('columns-add-refused@example.com');
        $crawler = $this->board($project);

        $crawler = $this->client->submit($crawler->filter('form[action$="/board/columns"]')->form(['add_board_column_form[label]' => 'Done']));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('already has this slug', $crawler->filter('.lp-board__add-column .lp-field-errors')->text());
        self::assertSame(['backlog', 'next', 'in-progress', 'done'], $this->slugs($project));
    }

    public function test_the_owner_renames_a_column_through_its_dialog(): void
    {
        [, $project] = $this->ownedBoard('columns-rename@example.com');
        $next = $this->column($project, 'next');
        $crawler = $this->board($project);

        $name = RenameBoardColumnFormType::nameFor($next);
        $form = $crawler->filter('form[name="'.$name.'"]');
        // A seeded label is a translation key, and the dialog offers the name the board shows.
        self::assertSame('Next', $form->filter('input[name="'.$name.'[label]"]')->attr('value'));

        $this->client->submit($form->form(), [$name.'[label]' => 'Up next']);

        self::assertResponseRedirects('/projects/'.$project->id.'/board');
        self::assertSame(['backlog', 'up-next', 'in-progress', 'done'], $this->slugs($project));
    }

    public function test_a_refused_rename_reopens_its_dialog_with_the_error(): void
    {
        [, $project] = $this->ownedBoard('columns-rename-refused@example.com');
        $next = $this->column($project, 'next');
        $crawler = $this->board($project);

        $name = RenameBoardColumnFormType::nameFor($next);
        $crawler = $this->client->submit($crawler->filter('form[name="'.$name.'"]')->form(), [$name.'[label]' => '🚀']);

        self::assertResponseStatusCodeSame(422);
        $menu = $crawler->filter('[data-column-id="'.$next->id.'"] .lp-board__column-menu');
        self::assertCount(1, $menu->filter('[data-modal-reopen-value="true"]'));
        self::assertCount(0, $menu->filter('.lp-board__column-menu-panel[hidden]'));
        self::assertStringContainsString('at least one letter or digit', $menu->filter('.lp-field-errors')->text());
        self::assertSame(['backlog', 'next', 'in-progress', 'done'], $this->slugs($project));
    }

    public function test_the_rename_preview_answers_with_the_slug_the_server_derives(): void
    {
        [, $project] = $this->ownedBoard('columns-preview@example.com');
        $next = $this->column($project, 'next');

        $crawler = $this->client->request(Request::METHOD_GET, $this->columnUrl($project, $next, 'rename-preview').'?label='.rawurlencode('完了'));

        self::assertResponseIsSuccessful();
        self::assertSame('wan-le', $crawler->filter('turbo-frame#board-column-slug-'.$next->id.' code')->text());
    }

    public function test_the_owner_moves_a_column_right_from_its_menu(): void
    {
        [, $project] = $this->ownedBoard('columns-move@example.com');
        $crawler = $this->board($project);

        $button = $crawler->filter('[data-column-slug="backlog"] .lp-board__column-menu-item')->reduce(
            static fn (Crawler $item): bool => 'Move right' === trim($item->text()),
        );
        $this->client->submit($button->form());

        self::assertResponseRedirects('/projects/'.$project->id.'/board');
        self::assertSame(['next', 'backlog', 'in-progress', 'done'], $this->slugs($project));
    }

    public function test_the_owner_marks_a_column_terminal_and_makes_another_the_default(): void
    {
        [, $project] = $this->ownedBoard('columns-flags@example.com');
        $crawler = $this->board($project);

        $this->client->submit($crawler->filter('form[action$="/columns/'.$this->column($project, 'in-progress')->id.'/terminal"]')->form());
        self::assertResponseRedirects('/projects/'.$project->id.'/board');

        $crawler = $this->board($project);
        $this->client->submit($crawler->filter('form[action$="/columns/'.$this->column($project, 'next')->id.'/default"]')->form());
        self::assertResponseRedirects('/projects/'.$project->id.'/board');

        $this->em->clear();
        self::assertTrue($this->column($project, 'in-progress')->terminal);
        self::assertTrue($this->column($project, 'next')->isDefault);
        self::assertFalse($this->column($project, 'backlog')->isDefault);
    }

    public function test_the_board_offers_no_delete_for_the_default_or_the_last_terminal_column(): void
    {
        [, $project] = $this->ownedBoard('columns-no-delete@example.com');

        $crawler = $this->board($project);

        self::assertCount(0, $crawler->filter('form[name="'.DeleteBoardColumnFormType::nameFor($this->column($project, 'backlog')).'"]'));
        self::assertCount(0, $crawler->filter('form[name="'.DeleteBoardColumnFormType::nameFor($this->column($project, 'done')).'"]'));
        self::assertCount(1, $crawler->filter('form[name="'.DeleteBoardColumnFormType::nameFor($this->column($project, 'next')).'"]'));
    }

    public function test_the_delete_dialog_counts_the_cards_and_lists_only_the_other_columns(): void
    {
        [, $project] = $this->ownedBoard('columns-delete-dialog@example.com');
        $this->card($this->em, $project, 'One', 'next');
        $this->card($this->em, $project, 'Two', 'next');
        $next = $this->column($project, 'next');
        $this->em->clear();

        $crawler = $this->board($project);

        $name = DeleteBoardColumnFormType::nameFor($next);
        $dialog = $crawler->filter('form[name="'.$name.'"]')->ancestors()->filter('dialog');
        self::assertStringContainsString('holds 2 cards', $dialog->text());
        $choices = $crawler->filter('select[name="'.$name.'[target]"] option')->each(
            static fn (Crawler $option): string => $option->attr('value') ?? '',
        );
        $expected = array_map(fn (string $slug): string => (string) $this->column($project, $slug)->id, ['backlog', 'in-progress', 'done']);
        self::assertSame($expected, array_values(array_filter($choices, static fn (string $value): bool => '' !== $value)));
    }

    public function test_the_owner_deletes_a_column_and_its_cards_move_to_the_target(): void
    {
        [, $project] = $this->ownedBoard('columns-delete@example.com');
        $card = $this->card($this->em, $project, 'Travelling', 'next');
        $cardId = $card->id;
        $next = $this->column($project, 'next');
        $target = (string) $this->column($project, 'backlog')->id;
        $this->em->clear();
        $crawler = $this->board($project);

        $name = DeleteBoardColumnFormType::nameFor($next);
        $this->client->submit($crawler->filter('form[name="'.$name.'"]')->form([$name.'[target]' => $target]));

        self::assertResponseRedirects('/projects/'.$project->id.'/board');
        self::assertSame(['backlog', 'in-progress', 'done'], $this->slugs($project));
        $this->em->clear();
        $moved = $this->em->find(Card::class, $cardId);
        self::assertInstanceOf(Card::class, $moved);
        self::assertSame('backlog', $moved->column->slug);

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'moved its card');
    }

    public function test_a_column_deleted_from_a_terminal_into_an_open_target_clears_the_completion_of_its_cards(): void
    {
        [, $project] = $this->ownedBoard('columns-delete-terminal@example.com');
        // A second terminal column, so done is not the last one and may go.
        $this->column($project, 'in-progress')->terminal = true;
        $card = $this->card($this->em, $project, 'Finished', 'done');
        $card->completedAt = new \DateTimeImmutable();
        $this->em->flush();
        $cardId = $card->id;
        $done = $this->column($project, 'done');
        $target = (string) $this->column($project, 'next')->id;
        $crawler = $this->board($project);

        $name = DeleteBoardColumnFormType::nameFor($done);
        $this->client->submit($crawler->filter('form[name="'.$name.'"]')->form([$name.'[target]' => $target]));

        self::assertResponseRedirects('/projects/'.$project->id.'/board');
        self::assertSame(['backlog', 'next', 'in-progress'], $this->slugs($project));
        $moved = $this->em->find(Card::class, $cardId);
        self::assertInstanceOf(Card::class, $moved);
        self::assertSame('next', $moved->column->slug);
        self::assertNull($moved->completedAt);
    }

    public function test_a_delete_with_a_bad_csrf_token_says_so(): void
    {
        [, $project] = $this->ownedBoard('columns-delete-csrf@example.com');
        $next = $this->column($project, 'next');
        $crawler = $this->board($project);

        $name = DeleteBoardColumnFormType::nameFor($next);
        $this->client->submit($crawler->filter('form[name="'.$name.'"]')->form([$name.'[_token]' => 'forged']));

        self::assertResponseRedirects('/projects/'.$project->id.'/board');
        self::assertSame(['backlog', 'next', 'in-progress', 'done'], $this->slugs($project));
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'The page expired');
    }

    /** @return iterable<string, array{string, string}> */
    public static function managedRoutes(): iterable
    {
        yield 'add' => [Request::METHOD_POST, '/columns'];
        yield 'reorder' => [Request::METHOD_POST, '/columns/reorder'];
        yield 'rename' => [Request::METHOD_POST, 'rename'];
        yield 'rename preview' => [Request::METHOD_GET, 'rename-preview'];
        yield 'terminal' => [Request::METHOD_POST, 'terminal'];
        yield 'not terminal' => [Request::METHOD_POST, 'not-terminal'];
        yield 'default' => [Request::METHOD_POST, 'default'];
        yield 'delete' => [Request::METHOD_POST, 'delete'];
    }

    #[DataProvider('managedRoutes')]
    public function test_a_stranger_cannot_change_the_columns(string $method, string $action): void
    {
        [, $project] = $this->ownedBoard('columns-owner-'.ltrim(str_replace('/', '-', $action), '-').'@example.com');
        $next = $this->column($project, 'next');
        $url = str_starts_with($action, '/')
            ? '/projects/'.$project->id.'/board'.$action
            : $this->columnUrl($project, $next, $action);
        $this->client->loginUser($this->user($this->em, 'columns-stranger-'.ltrim(str_replace('/', '-', $action), '-').'@example.com'));

        // The same-origin sentinel passes the CSRF check, so the 403 is the voter's.
        $this->client->request($method, $url, ['_csrf_token' => 'csrf-token'], [], ['HTTP_REFERER' => 'http://localhost'.$url]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertSame(['backlog', 'next', 'in-progress', 'done'], $this->slugs($project));
        self::assertFalse($this->column($project, 'next')->terminal);
        self::assertTrue($this->column($project, 'backlog')->isDefault);
    }

    /** @return iterable<string, array{string, string}> */
    public static function columnRoutes(): iterable
    {
        yield 'rename' => [Request::METHOD_POST, 'rename'];
        yield 'rename preview' => [Request::METHOD_GET, 'rename-preview'];
        yield 'terminal' => [Request::METHOD_POST, 'terminal'];
        yield 'not terminal' => [Request::METHOD_POST, 'not-terminal'];
        yield 'default' => [Request::METHOD_POST, 'default'];
        yield 'delete' => [Request::METHOD_POST, 'delete'];
    }

    #[DataProvider('columnRoutes')]
    public function test_a_column_of_another_project_is_not_found_under_this_one(string $method, string $action): void
    {
        [$owner, $project] = $this->ownedBoard('columns-cross-'.$action.'@example.com');
        $other = $this->project($this->em, $owner, 'other-board');
        $foreign = $this->column($other, 'next');
        $foreignId = $foreign->id;

        // The CSRF check runs before the column is looked up, so the request
        // carries the same-origin sentinel a token check accepts.
        $url = $this->columnUrl($project, $foreign, $action);
        $this->client->request($method, $url, ['_csrf_token' => 'csrf-token'], [], ['HTTP_REFERER' => 'http://localhost'.$url]);

        self::assertResponseStatusCodeSame(404);
        $this->em->clear();
        $untouched = $this->em->find(BoardColumn::class, $foreignId);
        self::assertInstanceOf(BoardColumn::class, $untouched);
        self::assertSame('next', $untouched->slug);
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{User, Project}
     */
    private function ownedBoard(string $email): array
    {
        $owner = $this->user($this->em, $email);
        $project = $this->project($this->em, $owner);
        $this->client->loginUser($owner);

        return [$owner, $project];
    }

    private function board(Project $project): Crawler
    {
        $this->em->clear();
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function columnUrl(Project $project, BoardColumn $column, string $action): string
    {
        return '/projects/'.$project->id.'/board/columns/'.$column->id.'/'.$action;
    }

    /** @return list<string> */
    private function slugs(Project $project): array
    {
        $this->em->clear();
        $repository = static::getContainer()->get(BoardColumnRepository::class);
        self::assertInstanceOf(BoardColumnRepository::class, $repository);
        $fresh = $this->em->find(Project::class, $project->id);
        self::assertInstanceOf(Project::class, $fresh);

        return array_map(static fn (BoardColumn $column): string => $column->slug, $repository->findForProject($fresh));
    }
}
