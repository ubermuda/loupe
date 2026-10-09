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

    public function test_a_status_box_carries_its_kind_state_reason_and_since(): void
    {
        $html = $this->render('<twig:Ds:StatusBox kind="needs-you" label="Needs you" lead="A document waits." since="Since 2 minutes ago" data-x />');

        self::assertStringContainsString('class="lp-status-box lp-status-box--needs-you"', $html);
        self::assertStringContainsString('<strong class="lp-status-box__state">Needs you</strong>', $html);
        self::assertStringContainsString('<span class="lp-status-box__since">Since 2 minutes ago</span>', $html);
        self::assertStringContainsString('<p class="lp-status-box__lead">A document waits.</p>', $html);
        self::assertStringContainsString('data-x', $html);
        self::assertStringNotContainsString('lp-status-box__others', $html);
    }

    public function test_a_status_box_leaves_out_the_since_line_when_it_has_none(): void
    {
        $html = $this->render('<twig:Ds:StatusBox kind="waiting" label="Waiting" lead="Blocked." />');

        self::assertStringNotContainsString('lp-status-box__since', $html);
    }

    public function test_a_status_box_lists_the_other_states(): void
    {
        $html = $this->render('<twig:Ds:StatusBox kind="stuck" label="Stuck" lead="Paused." othersTitle="Also applies" :others="[{kind: \'working\', label: \'Working\', lead: \'A worker runs.\', since: \'Since now\'}, {kind: \'waiting\', label: \'Waiting\', lead: \'Blocked.\', since: null}]" />');

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
