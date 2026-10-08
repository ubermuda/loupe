<?php

declare(strict_types=1);

namespace App\Tests\DesignSystem;

use PHPUnit\Framework\TestCase;

final class WidgetTokensMatchAppTest extends TestCase
{
    private const array WIDGET_ONLY = [
        '--font', '--mono', '--bar-shadow', '--pin-ring', '--pin-shadow',
        '--field-focus', '--accent-fill', '--shadow', '--scrim',
    ];

    public function test_widget_values_equal_the_app_tokens(): void
    {
        $app = $this->appTokens();
        $widget = [...$this->widgetMap('CHROME'), ...$this->widgetMap('LIGHT')];

        self::assertNotEmpty($widget);
        $mapped = 0;
        foreach ($widget as $name => $value) {
            if (\in_array($name, self::WIDGET_ONLY, true)) {
                continue;
            }
            self::assertArrayHasKey($name, $app, \sprintf('The widget token %s has no app token of that name.', $name));
            self::assertSame($app[$name], $value, \sprintf('The widget token %s drifted from tokens.css.', $name));
            ++$mapped;
        }
        self::assertGreaterThan(15, $mapped);
    }

    public function test_dark_map_has_the_names_of_the_light_map(): void
    {
        $light = array_keys($this->widgetMap('LIGHT'));
        $dark = array_keys($this->widgetMap('DARK'));
        sort($light);
        sort($dark);

        self::assertSame($light, $dark);
    }

    /**
     * @return array<string, string>
     */
    private function appTokens(): array
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/assets/styles/tokens.css');
        self::assertSame(1, preg_match('/:root\s*\{(.*?)\n\}/s', $css, $block));
        preg_match_all('/^\s*(--[\w-]+):\s*([^;]+);/m', $block[1], $m, PREG_SET_ORDER);

        $tokens = [];
        foreach ($m as $row) {
            $tokens[$row[1]] = strtolower(trim($row[2]));
        }

        return $tokens;
    }

    /**
     * @return array<string, string>
     */
    private function widgetMap(string $name): array
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/public/site-review/widget.js');
        self::assertSame(1, preg_match('/const '.$name.' = \{(.*?)\n    \};/s', $src, $block));
        preg_match_all("/'(--[\w-]+)':\s*(?:\n\s*)?['\"]([^'\"]+)['\"]/", $block[1], $m, PREG_SET_ORDER);

        $map = [];
        foreach ($m as $row) {
            $map[$row[1]] = strtolower(trim($row[2]));
        }

        return $map;
    }
}
