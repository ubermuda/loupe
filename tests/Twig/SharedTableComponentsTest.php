<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

final class SharedTableComponentsTest extends KernelTestCase
{
    public function test_a_chip_with_no_reason_has_no_tooltip(): void
    {
        $chip = $this->render("{{ component('Ds:StatusChip', {modifier: 'ok', label: 'Succeeded'}) }}")->filter('.lp-status-chip');

        self::assertCount(1, $chip);
        self::assertStringContainsString('lp-status-chip--ok', (string) $chip->attr('class'));
        self::assertStringNotContainsString('lp-status-chip--reason', (string) $chip->attr('class'));
        self::assertSame('Succeeded', trim($chip->text()));
        self::assertNull($chip->attr('tabindex'));
        self::assertCount(0, $chip->filter('[role="tooltip"]'));
    }

    public function test_a_chip_with_a_reason_describes_itself_with_a_tooltip(): void
    {
        $chip = $this->render("{{ component('Ds:StatusChip', {modifier: 'failed', label: 'Failed', reason: 'Exit code 2'}) }}")->filter('.lp-status-chip');

        self::assertStringContainsString('lp-status-chip--reason', (string) $chip->attr('class'));
        self::assertSame('0', $chip->attr('tabindex'));
        $tooltip = $chip->filter('.lp-tooltip[role="tooltip"]');
        self::assertCount(1, $tooltip);
        self::assertSame('Exit code 2', trim($tooltip->text()));
        self::assertNotEmpty($tooltip->attr('id'));
        self::assertSame($tooltip->attr('id'), $chip->attr('aria-describedby'));
    }

    public function test_two_chips_with_a_reason_get_distinct_tooltip_ids(): void
    {
        $crawler = $this->render("{{ component('Ds:StatusChip', {modifier: 'failed', label: 'A', reason: 'x'}) }}{{ component('Ds:StatusChip', {modifier: 'failed', label: 'B', reason: 'y'}) }}");

        $ids = $crawler->filter('.lp-tooltip')->each(static fn (Crawler $tooltip): ?string => $tooltip->attr('id'));
        self::assertCount(2, $ids);
        self::assertNotSame($ids[0], $ids[1]);
    }

    public function test_a_chip_takes_a_given_tooltip_id(): void
    {
        $chip = $this->render("{{ component('Ds:StatusChip', {modifier: 'failed', label: 'Failed', reason: 'x', id: 'run-7-reason'}) }}")->filter('.lp-status-chip');

        self::assertSame('run-7-reason', $chip->attr('aria-describedby'));
        self::assertSame('run-7-reason', $chip->filter('.lp-tooltip')->attr('id'));
    }

    public function test_a_table_renders_its_header_and_its_rows(): void
    {
        $crawler = $this->render(<<<'TWIG'
            {% component 'DataTable' with {columns: [{label: 'Work'}, {label: 'Duration', align: 'end'}], modifier: 'runs'} %}
                {% block content %}<div class="lp-data-table__row" data-row>One</div>{% endblock %}
            {% endcomponent %}
            TWIG);

        $table = $crawler->filter('.lp-data-table');
        self::assertCount(1, $table);
        self::assertStringContainsString('lp-data-table--runs', (string) $table->attr('class'));
        self::assertNull($table->attr('columns'));
        $headings = $table->filter('.lp-data-table__header > span');
        self::assertSame(['Work', 'Duration'], $headings->each(static fn (Crawler $heading): string => trim($heading->text())));
        self::assertStringContainsString('lp-data-table__cell--end', (string) $headings->eq(1)->attr('class'));
        self::assertCount(1, $table->filter('[data-row]'));
    }

    private function render(string $source): Crawler
    {
        $twig = static::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return new Crawler($twig->createTemplate($source)->render());
    }
}
