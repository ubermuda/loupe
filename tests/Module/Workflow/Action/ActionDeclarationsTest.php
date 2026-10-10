<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Action;

use App\Module\Board\Workflow\Detach;
use App\Module\Board\Workflow\ForgeWrite;
use App\Module\Board\Workflow\LinkDocument;
use App\Module\Board\Workflow\MoveCard;
use App\Module\Board\Workflow\RequestWork;
use App\Module\Workflow\Action\Ask;
use App\Module\Workflow\Action\EvaluateChildren;
use App\Module\Workflow\Action\PauseCard;
use App\Module\Workflow\Action\ReleasePause;
use App\Module\Workflow\Contract\Action;
use App\Module\Workflow\Contract\ActionDescription;
use App\Module\Workflow\Contract\ActionTraits;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Template\TemplateParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** What each action declares to the engine, the parser and the screens. */
final class ActionDeclarationsTest extends TestCase
{
    /** @return iterable<string, array{class-string<Action>, string, string, ActionTraits}> */
    public static function declarations(): iterable
    {
        yield 'move' => [MoveCard::class, 'move', 'workflow.source.board', new ActionTraits(endsPass: true, option: true, childChoice: true)];
        yield 'request' => [RequestWork::class, 'request', 'workflow.source.bridge', new ActionTraits(countsTowardLimit: true, refreshesFacts: true)];
        yield 'forge-write' => [ForgeWrite::class, 'forge-write', 'workflow.source.forge', new ActionTraits(countsTowardLimit: true)];
        yield 'pause' => [PauseCard::class, 'pause', 'workflow.source.board', new ActionTraits()];
        yield 'release' => [ReleasePause::class, 'release', 'workflow.source.board', new ActionTraits()];
        yield 'evaluate' => [EvaluateChildren::class, 'evaluate', 'workflow.source.board', new ActionTraits()];
        yield 'ask' => [Ask::class, 'ask', 'workflow.source.board', new ActionTraits()];
        yield 'link-document' => [LinkDocument::class, 'link-document', 'workflow.source.board', new ActionTraits(option: true, childChoice: true)];
        yield 'detach' => [Detach::class, 'detach', 'workflow.source.board', new ActionTraits(option: true)];
    }

    /** @param class-string<Action> $class */
    #[DataProvider('declarations')]
    public function test_an_action_declares_its_key_source_and_traits(string $class, string $key, string $source, ActionTraits $traits): void
    {
        self::assertSame($key, $class::key());
        self::assertSame($source, $class::source());
        self::assertEquals($traits, $class::traits());
        $names = array_map(static fn (Parameter $parameter): string => $parameter->name, $class::parameters());
        self::assertSame(array_unique($names), $names, 'A parameter name is declared once.');
    }

    public function test_a_request_asks_a_bridge_for_its_kind_and_a_forge_write_for_its_fallback(): void
    {
        self::assertSame('fix', self::action(RequestWork::class)->workKind(['kind' => 'fix', 'limit' => 3]));
        self::assertSame('merge', self::action(ForgeWrite::class)->workKind(['write' => 'merge', 'fallback' => 'merge']));
        self::assertNull(self::action(ForgeWrite::class)->workKind(['write' => 'draft']));
        foreach ([MoveCard::class, PauseCard::class, ReleasePause::class, EvaluateChildren::class, Ask::class, LinkDocument::class, Detach::class] as $class) {
            self::assertNull(self::action($class)->workKind(['to' => 'build', 'reason' => 'r']), $class);
        }
    }

    public function test_a_move_to_a_slot_is_worded_with_the_label_of_the_slot(): void
    {
        $description = self::action(MoveCard::class)->describe(['to' => 'build']);

        self::assertEquals(new ActionDescription('workflow.settings.action.move', 'workflow.panel.action.move', panelSlots: ['%slot%' => 'build'], settingsTarget: 'build'), $description);
    }

    public function test_a_move_to_the_backlog_or_a_terminal_column_has_its_own_panel_text(): void
    {
        $backlog = self::action(MoveCard::class)->describe(['to' => '@backlog']);
        $terminal = self::action(MoveCard::class)->describe(['to' => '@terminal']);

        self::assertSame('workflow.panel.action.move_backlog', $backlog->panelKey);
        self::assertSame([], $backlog->panelSlots);
        self::assertSame('@backlog', $backlog->settingsTarget);
        self::assertSame('workflow.panel.action.move_terminal', $terminal->panelKey);
        self::assertSame('@terminal', $terminal->settingsTarget);
    }

    public function test_a_request_and_a_forge_write_show_their_detail(): void
    {
        $request = self::action(RequestWork::class)->describe(['kind' => 'fix']);
        $write = self::action(ForgeWrite::class)->describe(['write' => 'merge', 'fallback' => 'sync']);

        self::assertEquals(new ActionDescription('workflow.settings.action.request', 'workflow.panel.action.request', panelParams: ['%kind%' => 'fix'], settingsDetail: 'fix'), $request);
        self::assertEquals(new ActionDescription('workflow.settings.action.forge_write', 'workflow.panel.action.forge_write', panelParams: ['%write%' => 'merge'], settingsDetail: 'merge'), $write);
    }

    public function test_the_other_actions_have_fixed_texts(): void
    {
        $texts = [
            PauseCard::class => 'pause',
            ReleasePause::class => 'release',
            EvaluateChildren::class => 'evaluate',
            Ask::class => 'ask',
            LinkDocument::class => 'link_document',
            Detach::class => 'detach',
        ];
        foreach ($texts as $class => $name) {
            self::assertEquals(new ActionDescription('workflow.settings.action.'.$name, 'workflow.panel.action.'.$name), self::action($class)->describe([]), $class);
        }
    }

    public function test_only_a_request_takes_the_prompt_of_an_app_rule(): void
    {
        $prompts = array_filter(RequestWork::parameters(), static fn (Parameter $parameter): bool => $parameter->appOnly);

        self::assertSame([TemplateParser::PROMPT], array_map(static fn (Parameter $parameter): string => $parameter->name, array_values($prompts)));
    }

    public function test_a_forge_write_needs_a_fallback_unless_it_writes_a_state_or_opens_an_epic(): void
    {
        self::assertSame(['missing parameter "fallback"'], ForgeWrite::check(['write' => 'merge']));
        self::assertSame([], ForgeWrite::check(['write' => 'merge', 'fallback' => 'merge']));
        foreach (['draft', 'ready', 'close', 'open-epic', 'post-review', 'site-review-check'] as $write) {
            self::assertSame([], ForgeWrite::check(['write' => $write]), $write);
        }
        self::assertSame(['missing parameter "fallback"'], ForgeWrite::check([]));
        self::assertSame(['missing parameter "comment"'], ForgeWrite::check(['write' => 'comment']));
        self::assertSame([], ForgeWrite::check(['write' => 'comment', 'comment' => 'fix-run']));
    }

    /**
     * @template T of Action
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function action(string $class): Action
    {
        return new \ReflectionClass($class)->newInstanceWithoutConstructor();
    }
}
