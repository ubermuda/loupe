<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Module\DesignSystem\Catalog;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class StatusBoxComponentTest extends KernelTestCase
{
    private function render(string $template): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render();
    }

    public function test_a_status_box_carries_its_title_chip_reason_and_fields(): void
    {
        $html = $this->render('<twig:Ds:StatusBox kind="stuck" title="Status" chip="Stuck for 2 h" lead="A pull request waits." :fields="[{label: \'Since\', value: \'14:02, 2 hours ago\'}, {label: \'To unstick it\', value: \'Merge it.\', wide: true}]" data-x />');

        self::assertStringContainsString('class="lp-status-box lp-status-box--stuck"', $html);
        self::assertStringContainsString('<h2 class="lp-status-box__title">Status</h2>', $html);
        self::assertStringContainsString('lp-status-chip lp-status-chip--failed lp-status-box__chip', $html);
        self::assertStringContainsString('Stuck for 2 h', $html);
        self::assertStringContainsString('<p class="lp-status-box__lead">A pull request waits.</p>', $html);
        self::assertStringContainsString('<div class="lp-status-box__field">', $html);
        self::assertStringContainsString('<dd>14:02, 2 hours ago</dd>', $html);
        self::assertStringContainsString('<div class="lp-status-box__field lp-status-box__field--wide">', $html);
        self::assertStringContainsString('data-x', $html);
        self::assertStringNotContainsString('lp-status-box__others', $html);
    }

    public function test_a_status_box_with_no_fields_draws_no_field_list(): void
    {
        $html = $this->render('<twig:Ds:StatusBox kind="waiting" title="Status" chip="Waiting" lead="Blocked." />');

        self::assertStringNotContainsString('lp-status-box__fields', $html);
        self::assertStringContainsString('lp-status-chip--neutral', $html);
    }

    public function test_a_status_box_lists_the_other_states(): void
    {
        $html = $this->render('<twig:Ds:StatusBox kind="stuck" title="Status" chip="Stuck" lead="Paused." othersTitle="Also applies" :others="[{kind: \'working\', label: \'Working\', lead: \'A worker runs.\', since: \'Since now\'}, {kind: \'waiting\', label: \'Waiting\', lead: \'Blocked.\', since: null}]" />');

        self::assertStringContainsString('<p class="lp-status-box__others-title">Also applies</p>', $html);
        self::assertStringContainsString('lp-status-box__other lp-status-box__other--working', $html);
        self::assertStringContainsString('lp-status-box__other lp-status-box__other--waiting', $html);
        self::assertSame(2, substr_count($html, '<li class="lp-status-box__other'));
        self::assertStringContainsString('A worker runs.', $html);
    }

    public function test_the_catalog_lists_the_status_box_with_its_four_kinds(): void
    {
        $entry = array_find(new Catalog()->entries(), static fn ($entry): bool => 'StatusBox' === $entry->name);

        self::assertNotNull($entry);
        self::assertSame(['stuck', 'needs-you', 'working', 'waiting'], $entry->variants);
        self::assertTrue($entry->enforced);
    }
}
