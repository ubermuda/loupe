<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Twig;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\CardProgress;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\LabelTone;
use App\Module\Board\Form\AddBoardColumnRequest;
use App\Module\Board\Form\MoveCardFormType;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Service\BoardColumnTonePicker;
use App\Module\Board\Service\CardBadge;
use App\Module\Board\Service\CardDigest;
use App\Module\Board\Twig\BoardExtension;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\View\CardRunWarning;
use App\Module\Project\Entity\Project;
use App\Module\Review\Service\MarkdownRenderer;
use App\Tests\Module\Board\Controller\BoardScenario;
use Doctrine\ORM\EntityManagerInterface;
use Random\Engine;
use Random\Randomizer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class BoardExtensionTest extends KernelTestCase
{
    use BoardScenario;

    public function test_card_digest_ignores_the_rank_of_the_card_in_its_column(): void
    {
        $extension = static::getContainer()->get(BoardExtension::class);
        self::assertInstanceOf(BoardExtension::class, $extension);
        $card = $this->makeCard();

        $card->position = 2;
        $before = $extension->cardDigest($card, 0, 0, null, null, []);
        self::assertSame($before, $extension->cardDigest($card, 0, 0, null, null, []));

        $card->position = 0;
        self::assertSame($before, $extension->cardDigest($card, 0, 0, null, null, []));
    }

    public function test_card_digest_changes_with_the_document_count(): void
    {
        $extension = static::getContainer()->get(BoardExtension::class);
        self::assertInstanceOf(BoardExtension::class, $extension);
        $card = $this->makeCard();

        self::assertNotSame($extension->cardDigest($card, 0, 1, null, null, []), $extension->cardDigest($card, 0, 2, null, null, []));
    }

    public function test_card_digest_changes_with_the_progress_it_is_given(): void
    {
        $extension = static::getContainer()->get(BoardExtension::class);
        self::assertInstanceOf(BoardExtension::class, $extension);
        $card = $this->makeCard();

        self::assertNotSame(
            $extension->cardDigest($card, 0, 0, null, null, []),
            $extension->cardDigest($card, 0, 0, new CardProgress(1, 2), null, []),
        );
    }

    public function test_card_digest_changes_with_the_run_warning_the_card_shows(): void
    {
        $extension = static::getContainer()->get(BoardExtension::class);
        self::assertInstanceOf(BoardExtension::class, $extension);
        $card = $this->makeCard();

        $none = $extension->cardDigest($card, 0, 0, null, null, []);
        $first = $extension->cardDigest($card, 0, 0, null, $this->gaveUp('run-1'), []);

        self::assertNotSame($none, $first);
        self::assertSame($first, $extension->cardDigest($card, 0, 0, null, $this->gaveUp('run-1'), []));
        self::assertNotSame($first, $extension->cardDigest($card, 0, 0, null, $this->gaveUp('run-2'), []));
    }

    public function test_card_digest_changes_with_the_badges_it_is_given(): void
    {
        $extension = static::getContainer()->get(BoardExtension::class);
        self::assertInstanceOf(BoardExtension::class, $extension);
        $card = $this->makeCard();

        self::assertNotSame(
            $extension->cardDigest($card, 0, 0, null, null, []),
            $extension->cardDigest($card, 0, 0, null, null, [CardBadge::Conflict]),
        );
    }

    public function test_card_digest_changes_with_the_progress_of_an_epic(): void
    {
        $extension = static::getContainer()->get(BoardExtension::class);
        self::assertInstanceOf(BoardExtension::class, $extension);
        $card = $this->makeCard();

        $before = $extension->cardDigest($card, 0, 0, new CardProgress(1, 3), null, []);
        self::assertSame($before, $extension->cardDigest($card, 0, 0, new CardProgress(1, 3), null, []));
        self::assertNotSame($before, $extension->cardDigest($card, 0, 0, new CardProgress(2, 3), null, []));
        self::assertNotSame($before, $extension->cardDigest($card, 0, 0, new CardProgress(1, 4), null, []));
    }

    public function test_card_digest_tells_a_card_with_no_progress_from_an_epic_with_no_children(): void
    {
        $extension = static::getContainer()->get(BoardExtension::class);
        self::assertInstanceOf(BoardExtension::class, $extension);
        $card = $this->makeCard();

        self::assertNotSame($extension->cardDigest($card, 0, 0, null, null, []), $extension->cardDigest($card, 0, 0, new CardProgress(0, 0), null, []));
    }

    public function test_the_move_form_renders_under_the_placeholder_card(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->project($em, $this->user($em, 'fields-name@example.com'));

        $html = $this->renderFields($project);

        $name = 'move_card_'.MoveCardFormType::PLACEHOLDER_CARD_ID;
        foreach (['_token', 'column', 'position', 'parent', 'beforeCardId', 'afterCardId'] as $field) {
            self::assertStringContainsString('name="'.$name.'['.$field.']"', $html);
        }
        self::assertStringContainsString('id="'.$name.'_column"', $html);
        self::assertSame(substr_count($html, 'move_card_'), substr_count($html, $name));
    }

    public function test_the_add_form_preselects_no_colour_the_backlog_uses(): void
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $project = $this->project($em, $this->user($em, 'add-tone@example.com'));
        $firstFree = new Randomizer(new class implements Engine {
            public function generate(): string
            {
                return "\0\0\0\0\0\0\0\0";
            }
        });
        $extension = new BoardExtension(
            $container->get(FormFactoryInterface::class),
            $container->get(MarkdownRenderer::class),
            $container->get(TranslatorInterface::class),
            $container->get(CardDocumentRepository::class),
            $container->get(BoardColumnRepository::class),
            new BoardColumnTonePicker($firstFree),
            new CardDigest(),
        );

        // Neutral is the Backlog's tone and the first case, so it comes first when the Backlog is left out.
        $request = $extension->boardColumnAddForm($project)->vars['value'];
        self::assertInstanceOf(AddBoardColumnRequest::class, $request);
        self::assertSame(LabelTone::Amber, $request->tone);
    }

    public function test_each_project_gets_its_own_columns(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'fields-projects@example.com');
        $alpha = $this->project($em, $owner, 'alpha');
        $beta = $this->project($em, $owner, 'beta');

        $alphaOptions = $this->optionValues($this->renderFields($alpha));
        $betaOptions = $this->optionValues($this->renderFields($beta));

        self::assertSame($this->columnIds($alpha), $alphaOptions);
        self::assertSame($this->columnIds($beta), $betaOptions);
        self::assertSame([], array_intersect($alphaOptions, $betaOptions));
    }

    /** @return list<string> */
    private function optionValues(string $html): array
    {
        preg_match_all('/<option value="([^"]+)"/', $html, $matches);

        return $matches[1];
    }

    /** @return list<string> */
    private function columnIds(Project $project): array
    {
        $columns = static::getContainer()->get(BoardColumnRepository::class);
        self::assertInstanceOf(BoardColumnRepository::class, $columns);

        return array_map(static fn (BoardColumn $column): string => (string) $column->id, $columns->findForProject($project));
    }

    private function renderFields(Project $project): string
    {
        return $this->twig()->createTemplate(
            '{% set form = board_move_form(project) %}{% for field in form %}{{ form_widget(field) }}{% endfor %}',
        )->render(['project' => $project]);
    }

    private function twig(): Environment
    {
        $twig = static::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig;
    }

    private function gaveUp(string $runId): CardRunWarning
    {
        return new CardRunWarning($runId, WorkerRunState::GaveUp, 'Tests fail.', null);
    }

    private function makeCard(): Card
    {
        $project = new Project(new User(fullName: 'Owner', email: 'owner@example.com', password: 'hashed'), 'p');

        return new Card(project: $project, column: new BoardColumn(project: $project, label: 'Backlog', slug: 'backlog', position: 0), title: 'Ship the board', body: 'Body', number: 1);
    }
}
