<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\LabelTone;
use App\Module\Board\Form\ConfigureBoardColumnFormType;
use App\Module\Board\Form\DeleteBoardColumnFormType;
use App\Module\Board\Form\RenameBoardColumnFormType;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
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

    public function test_settings_keeps_a_refused_add_and_a_successful_add_on_the_settings_page(): void
    {
        [, $project] = $this->ownedBoard('columns-settings-add@example.com');
        $url = '/projects/'.$project->id.'/settings/columns';
        $this->em->clear();
        $crawler = $this->client->request(Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-board-column-settings]'));

        $crawler = $this->client->submit($crawler->filter('form[name="add_board_column_form"]')->form(['add_board_column_form[label]' => 'Done']));
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('[data-board-column-settings]'));
        self::assertStringContainsString('already has this slug', $crawler->filter('form[name="add_board_column_form"] .lp-field-errors')->text());
        self::assertSame(['backlog', 'next', 'in-progress', 'done'], $this->slugs($project));

        $this->client->submit($crawler->filter('form[name="add_board_column_form"]')->form(['add_board_column_form[label]' => 'Parked']));
        self::assertResponseRedirects($url);
        $this->client->followRedirect();
        self::assertSelectorExists('[data-board-column-settings]');
        self::assertSame(['backlog', 'next', 'in-progress', 'done', 'parked'], $this->slugs($project));
    }

    public function test_the_add_dialog_preselects_a_colour_no_column_uses_and_saves_the_one_chosen(): void
    {
        [, $project] = $this->ownedBoard('columns-settings-add-tone@example.com');
        $crawler = $this->settings($project);

        $checked = $crawler->filter('form[name="add_board_column_form"] input[name="add_board_column_form[tone]"]:checked');
        self::assertCount(1, $checked);
        self::assertNotContains($checked->attr('value'), ['neutral', 'lime', 'purple', 'green']);

        $this->client->submit($crawler->filter('form[name="add_board_column_form"]')->form(['add_board_column_form[label]' => 'Parked', 'add_board_column_form[tone]' => 'pink']));
        self::assertSame(LabelTone::Pink, $this->column($project, 'parked')->tone);
    }

    public function test_settings_shows_the_stored_colour_and_a_configure_save_changes_it(): void
    {
        [, $project] = $this->ownedBoard('columns-configure-tone@example.com');
        $name = ConfigureBoardColumnFormType::nameFor($this->column($project, 'next'));
        self::assertSame('lime', $this->settings($project)->filter('input[name="'.$name.'[tone]"]:checked')->attr('value'));

        $this->configure($project, 'next', ['tone' => 'orange']);
        self::assertResponseRedirects('/projects/'.$project->id.'/settings/columns');

        self::assertSame(LabelTone::Orange, $this->column($project, 'next')->tone);
        self::assertCount(1, $this->settings($project)->filter('[data-column-id] > .lp-tone-dot--orange'));
    }

    public function test_settings_rows_move_up_and_down_and_open_a_configure_dialog(): void
    {
        [, $project] = $this->ownedBoard('columns-settings-rows@example.com');
        $crawler = $this->settings($project);

        $rows = $crawler->filter('[data-board-column-settings] [data-column-id]');
        self::assertCount(4, $rows);
        self::assertCount(0, $rows->first()->filter('button[aria-label="Move up"]'));
        self::assertCount(1, $rows->first()->filter('button[aria-label="Move down"]'));
        self::assertCount(1, $rows->last()->filter('button[aria-label="Move up"]'));
        self::assertCount(0, $rows->last()->filter('button[aria-label="Move down"]'));
        self::assertCount(4, $rows->filter('button[aria-label^="Configure "]'));
        self::assertCount(0, $rows->filter('.lp-board__column-menu'));
        // The default and the last terminal column cannot go, as on the board.
        self::assertCount(2, $rows->filter('button[aria-label^="Delete "]'));

        $this->client->submit($rows->eq(1)->filter('button[aria-label="Move down"]')->form());
        self::assertResponseRedirects('/projects/'.$project->id.'/settings/columns');
        self::assertSame(['backlog', 'in-progress', 'next', 'done'], $this->slugs($project));
    }

    public function test_settings_configures_the_name_the_default_and_the_terminal_flag_in_one_save(): void
    {
        [, $project] = $this->ownedBoard('columns-configure@example.com');
        $url = '/projects/'.$project->id.'/settings/columns';

        $this->configure($project, 'in-progress', ['terminal' => true]);
        self::assertResponseRedirects($url);

        // Done leaves the terminal flag and takes the default in one save, which
        // neither change could do alone.
        $this->configure($project, 'done', ['label' => 'Shipped', 'isDefault' => true, 'terminal' => false]);
        self::assertResponseRedirects($url);

        self::assertSame(['backlog', 'next', 'in-progress', 'shipped'], $this->slugs($project));
        self::assertTrue($this->column($project, 'shipped')->isDefault);
        self::assertFalse($this->column($project, 'shipped')->terminal);
        self::assertSame('Shipped', $this->column($project, 'shipped')->label);
        self::assertFalse($this->column($project, 'backlog')->isDefault);
        self::assertTrue($this->column($project, 'in-progress')->terminal);
        self::assertSame('human', $this->outboxPayload($project, 'board.column_renamed')['actor'] ?? null);
    }

    public function test_a_configure_save_that_keeps_the_shown_name_keeps_a_seeded_label(): void
    {
        [, $project] = $this->ownedBoard('columns-configure-seeded@example.com');
        $seeded = $this->column($project, 'in-progress')->label;

        $this->configure($project, 'in-progress', ['terminal' => true]);

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/columns');
        $this->em->clear();
        self::assertSame($seeded, $this->column($project, 'in-progress')->label);
        self::assertTrue($this->column($project, 'in-progress')->terminal);
    }

    /** @return iterable<string, array{string, array<string, string|bool>, string, string}> */
    public static function refusedConfigurations(): iterable
    {
        yield 'a taken slug' => ['next', ['label' => 'Done'], 'label', 'already has this slug'];
        yield 'a reserved label' => ['next', ['label' => 'board.column.flag.default'], 'label', 'uses this text internally'];
        yield 'no default left' => ['backlog', ['isDefault' => false], 'isDefault', 'exactly one default column'];
        yield 'a terminal default' => ['next', ['isDefault' => true, 'terminal' => true], 'terminal', 'default column cannot be terminal'];
        yield 'no terminal left' => ['done', ['terminal' => false], 'terminal', 'at least one terminal column'];
    }

    /** @param array<string, string|bool> $values */
    #[DataProvider('refusedConfigurations')]
    public function test_a_refused_configure_reopens_its_dialog_with_the_draft_and_the_error(string $slug, array $values, string $field, string $message): void
    {
        [, $project] = $this->ownedBoard('columns-configure-refused-'.$field.'-'.$slug.'@example.com');
        $column = $this->column($project, $slug);
        $name = ConfigureBoardColumnFormType::nameFor($column);

        $crawler = $this->configure($project, $slug, $values + ['label' => 'Draft name']);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('[data-board-column-settings]'));
        $form = $crawler->filter('form[name="'.$name.'"]');
        self::assertCount(1, $form->ancestors()->filter('[data-modal-reopen-value="true"]'));
        self::assertSame($values['label'] ?? 'Draft name', $form->filter('input[name="'.$name.'[label]"]')->attr('value'));
        self::assertStringContainsString($message, $form->filter('[data-field-errors="'.$field.'"]')->text());
        self::assertSame(['backlog', 'next', 'in-progress', 'done'], $this->slugs($project));
        self::assertTrue($this->column($project, 'backlog')->isDefault);
        self::assertSame(['done'], array_values(array_map(
            static fn (BoardColumn $column): string => $column->slug,
            array_filter($this->columns($project), static fn (BoardColumn $column): bool => $column->terminal),
        )));
    }

    public function test_a_stale_configure_keeps_the_newer_name_and_the_rejected_draft(): void
    {
        [, $project] = $this->ownedBoard('columns-configure-stale-name@example.com');
        $next = $this->column($project, 'next');
        $name = ConfigureBoardColumnFormType::nameFor($next);
        $crawler = $this->settings($project);
        $stale = $crawler->filter('form[name="'.$name.'"]')->form([$name.'[label]' => 'My draft']);
        $fresh = $crawler->filter('form[name="'.$name.'"]')->form([$name.'[label]' => 'Newer name']);
        $this->client->submit($fresh);
        self::assertResponseRedirects('/projects/'.$project->id.'/settings/columns');

        $crawler = $this->client->submit($stale);

        self::assertResponseStatusCodeSame(422);
        $form = $crawler->filter('form[name="'.$name.'"]');
        self::assertSame('My draft', $form->filter('input[name="'.$name.'[label]"]')->attr('value'));
        self::assertStringContainsString('changed after you opened', $form->filter('[data-field-errors="label"]')->text());
        self::assertSame(['backlog', 'newer-name', 'in-progress', 'done'], $this->slugs($project));
    }

    public function test_a_stale_configure_keeps_the_newer_default(): void
    {
        [, $project] = $this->ownedBoard('columns-configure-stale-default@example.com');
        $nextName = ConfigureBoardColumnFormType::nameFor($this->column($project, 'next'));
        $progressName = ConfigureBoardColumnFormType::nameFor($this->column($project, 'in-progress'));
        $crawler = $this->settings($project);
        $stale = $crawler->filter('form[name="'.$progressName.'"]')->form();
        $this->fill($stale, $progressName.'[isDefault]', true);
        $fresh = $crawler->filter('form[name="'.$nextName.'"]')->form();
        $this->fill($fresh, $nextName.'[isDefault]', true);
        $this->client->submit($fresh);
        self::assertResponseRedirects('/projects/'.$project->id.'/settings/columns');

        $crawler = $this->client->submit($stale);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The default column changed', $crawler->filter('form[name="'.$progressName.'"] [data-field-errors="isDefault"]')->text());
        $this->em->clear();
        self::assertTrue($this->column($project, 'next')->isDefault);
        self::assertFalse($this->column($project, 'in-progress')->isDefault);
    }

    public function test_a_stale_configure_does_not_undo_a_newer_terminal_flag(): void
    {
        [, $project] = $this->ownedBoard('columns-configure-stale-terminal@example.com');
        $name = ConfigureBoardColumnFormType::nameFor($this->column($project, 'in-progress'));
        $crawler = $this->settings($project);
        $stale = $crawler->filter('form[name="'.$name.'"]')->form();
        $fresh = $crawler->filter('form[name="'.$name.'"]')->form();
        $this->fill($fresh, $name.'[terminal]', true);
        $this->client->submit($fresh);
        self::assertResponseRedirects('/projects/'.$project->id.'/settings/columns');

        $crawler = $this->client->submit($stale);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('changed after this page opened', $crawler->filter('form[name="'.$name.'"] [data-field-errors="terminal"]')->text());
        $this->em->clear();
        self::assertTrue($this->column($project, 'in-progress')->terminal);
    }

    public function test_a_stale_move_preserves_the_order_saved_by_another_editor(): void
    {
        [, $project] = $this->ownedBoard('columns-settings-stale-order@example.com');
        $url = '/projects/'.$project->id.'/settings/columns';
        $this->em->clear();
        $crawler = $this->client->request(Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[action$="/reorder?view=settings"]')->first()->form();
        $this->client->submit($form);
        self::assertResponseRedirects($url);
        $saved = $this->slugs($project);
        self::assertSame(['next', 'backlog', 'in-progress', 'done'], $saved);

        $order = array_map(fn (string $slug): string => (string) $this->column($project, $slug)->id, ['backlog', 'next', 'done', 'in-progress']);
        $form['reorder_board_columns_form[order]'] = implode(',', $order);
        $this->client->submit($form);
        self::assertResponseRedirects($url);
        $this->client->followRedirect();
        self::assertSame($saved, $this->slugs($project));
    }

    public function test_the_owner_sees_the_column_controls(): void
    {
        [, $project] = $this->ownedBoard('columns-controls@example.com');

        $crawler = $this->board($project);

        self::assertCount(4, $crawler->filter('.lp-board__column-menu'));
        self::assertCount(4, $crawler->filter('[data-board-columns-target="column"] [draggable="true"]'));
        // Columns are added from board settings, never from the board itself.
        self::assertCount(0, $crawler->filter('form[action$="/board/columns"]'));
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
        self::assertSame('human', $this->outboxPayload($project, 'board.column_renamed')['actor'] ?? null);
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

    public function test_an_empty_column_is_deleted_from_a_confirmation_dialog_with_no_target_picker(): void
    {
        [, $project] = $this->ownedBoard('columns-delete-empty-dialog@example.com');
        $name = DeleteBoardColumnFormType::nameFor($this->column($project, 'next'));
        $this->em->clear();

        $crawler = $this->board($project);

        $form = $crawler->filter('form[name="'.$name.'"]');
        self::assertCount(1, $form);
        self::assertStringContainsString('holds no cards', $form->ancestors()->filter('dialog')->text());
        self::assertCount(0, $form->filter('select'));
        self::assertCount(1, $form->filter('input[name="'.$name.'[_token]"]'));
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
        self::assertSame('human', $this->outboxPayload($project, 'board.column_deleted')['actor'] ?? null);

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
        yield 'configure' => [Request::METHOD_POST, 'configure'];
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
        yield 'configure' => [Request::METHOD_POST, 'configure'];
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

    private function settings(Project $project): Crawler
    {
        $this->em->clear();
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/columns');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * Opens settings and submits one column's configure dialog. A boolean
     * value ticks or unticks its checkbox, and any field left out keeps what
     * the dialog shows.
     *
     * @param array<string, string|bool> $values
     */
    private function configure(Project $project, string $slug, array $values): Crawler
    {
        $name = ConfigureBoardColumnFormType::nameFor($this->column($project, $slug));
        $form = $this->settings($project)->filter('form[name="'.$name.'"]')->form();
        foreach ($values as $field => $value) {
            $this->fill($form, $name.'['.$field.']', $value);
        }

        return $this->client->submit($form);
    }

    /** A boolean ticks or unticks a checkbox, and a string fills a field. */
    private function fill(Form $form, string $field, string|bool $value): void
    {
        if (\is_string($value)) {
            $form[$field] = $value;

            return;
        }
        $checkbox = $form[$field];
        self::assertInstanceOf(ChoiceFormField::class, $checkbox);
        $value ? $checkbox->tick() : $checkbox->untick();
    }

    /** @return list<BoardColumn> */
    private function columns(Project $project): array
    {
        $this->em->clear();
        $repository = static::getContainer()->get(BoardColumnRepository::class);
        self::assertInstanceOf(BoardColumnRepository::class, $repository);
        $fresh = $this->em->find(Project::class, $project->id);
        self::assertInstanceOf(Project::class, $fresh);

        return $repository->findForProject($fresh);
    }

    private function columnUrl(Project $project, BoardColumn $column, string $action): string
    {
        return '/projects/'.$project->id.'/board/columns/'.$column->id.'/'.$action;
    }

    /** @return array<mixed> the decoded payload of the project's one outbox row of this type */
    private function outboxPayload(Project $project, string $type): array
    {
        $payloads = $this->em->getConnection()->fetchFirstColumn(
            'SELECT payload FROM outbox_events WHERE project_id = :project AND type = :type',
            ['project' => (string) $project->id, 'type' => $type],
        );
        self::assertCount(1, $payloads);
        self::assertIsString($payloads[0]);
        $payload = json_decode($payloads[0], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
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
