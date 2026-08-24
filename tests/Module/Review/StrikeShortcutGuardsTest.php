<?php

declare(strict_types=1);

namespace App\Tests\Module\Review;

use PHPUnit\Framework\TestCase;

/**
 * Tripwires for the two ways the strike shortcut can fire more often, or against a
 * different passage, than the reviewer intended. Both bugs are reachable only by a
 * human holding a key or clicking to clear a selection, and this project has no
 * JavaScript test harness — so these assert that the guards are still in the source,
 * not that they behave. Exercising them needs a browser; treat a failure here as
 * "someone removed a guard", and the real coverage as still owed.
 *
 * The same shape as WidgetFileTest, which asserts on the site-review widget source.
 */
final class StrikeShortcutGuardsTest extends TestCase
{
    private const string CONTROLLER = 'assets/controllers/comment_anchor_controller.js';

    public function test_a_cleared_selection_discards_the_captured_anchor(): void
    {
        $source = $this->controllerSource();

        // The shortcut reads pendingSelection, not the toolbar. If a mouseup that
        // clears the selection only hid the toolbar, `s` would stay armed against a
        // passage that is no longer selected.
        self::assertStringNotContainsString(
            "            this.#hideToolbar();\n            return;",
            $source,
            'onDocMouseup must clear the captured anchor, not just hide the toolbar',
        );
        // Counted inside onDocMouseup rather than across the file: the method has
        // exactly four ways to reject a selection — the fourth being a demo too
        // narrow to place a card — and a whole-file count also breaks when some
        // unrelated caller legitimately discards the anchor.
        self::assertSame(
            4,
            substr_count($this->methodSource($source, 'onDocMouseup'), 'this.#clearPendingSelection();'),
            'every invalid-selection branch in onDocMouseup must discard the anchor',
        );
    }

    public function test_repeat_and_in_flight_guards_bound_a_strike_to_one_submission(): void
    {
        $source = $this->controllerSource();

        self::assertStringContainsString('event.repeat', $source, 'auto-repeat must not post a strike per keydown');
        self::assertStringContainsString('this.strikeInFlight = true;', $source);
        self::assertStringContainsString('if (this.strikeInFlight) {', $source);

        // Released in onSubmitEnd ahead of the success check, so a rejected strike
        // does not disable the action for the rest of the page's life.
        $submitEnd = strpos($source, 'onSubmitEnd(event) {');
        self::assertIsInt($submitEnd);
        $release = strpos($source, 'this.strikeInFlight = false;', $submitEnd);
        $successCheck = strpos($source, 'event.detail?.success', $submitEnd);
        self::assertIsInt($release);
        self::assertIsInt($successCheck);
        self::assertLessThan($successCheck, $release);
    }

    private function controllerSource(): string
    {
        $path = dirname(__DIR__, 3).'/'.self::CONTROLLER;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * The body of one method, from its signature to the next one at the same
     * indentation. Crude, and good enough for a source tripwire: it only has to
     * stop a count leaking into neighbouring methods.
     */
    private function methodSource(string $source, string $name): string
    {
        $start = strpos($source, "\n    {$name}(");
        self::assertNotFalse($start, "method {$name} not found in the controller");

        $end = strpos($source, "\n    }\n", $start);
        self::assertNotFalse($end, "method {$name} is not closed as expected");

        return substr($source, $start, $end - $start);
    }
}
