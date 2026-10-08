<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Module\DesignSystem\Catalog;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class OverlayNavigationComponentsTest extends KernelTestCase
{
    private function render(string $template): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render();
    }

    public function test_a_dialog_carries_the_modal_target_and_its_attributes(): void
    {
        $html = $this->render('<twig:Ds:Dialog aria-labelledby="t" data-action="cancel->modal#close">Body</twig:Ds:Dialog>');

        self::assertStringContainsString('<dialog class="lp-dialog" data-modal-target="dialog"', $html);
        self::assertStringContainsString('aria-labelledby="t"', $html);
        self::assertStringContainsString('data-action="cancel-&gt;modal#close"', $html);
        self::assertStringContainsString('>Body</dialog>', $html);
    }

    public function test_a_dialog_size_adds_a_modifier_and_a_caller_class_is_appended(): void
    {
        $html = $this->render('<twig:Ds:Dialog size="document" class="lp-connect-dialog">Body</twig:Ds:Dialog>');

        self::assertStringContainsString('class="lp-dialog lp-dialog--document lp-connect-dialog"', $html);
        self::assertStringContainsString('data-modal-target="dialog"', $html);
    }

    public function test_tabs_render_a_nav_with_the_passed_attributes(): void
    {
        $html = $this->render('<twig:Ds:Tabs class="lp-analytics-tabs" aria-label="Sections"><a class="lp-tabs__tab" href="#">One</a></twig:Ds:Tabs>');

        self::assertStringContainsString('<nav class="lp-tabs lp-analytics-tabs" aria-label="Sections">', $html);
        self::assertStringContainsString('<a class="lp-tabs__tab" href="#">One</a></nav>', $html);
    }

    public function test_tabs_can_render_a_div_for_a_tablist(): void
    {
        $html = $this->render('<twig:Ds:Tabs tag="div" role="tablist">x</twig:Ds:Tabs>');

        self::assertStringContainsString('<div class="lp-tabs" role="tablist">x</div>', $html);
        self::assertStringNotContainsString('<nav', $html);
    }

    public function test_a_tooltip_carries_its_role_id_and_caller_class(): void
    {
        $html = $this->render('<twig:Ds:Tooltip id="tip-1" class="extra">Why</twig:Ds:Tooltip>');

        self::assertStringContainsString('<span class="lp-tooltip extra" role="tooltip" id="tip-1">Why</span>', $html);
    }

    public function test_pagination_renders_its_controls_and_nothing_for_one_page(): void
    {
        $html = $this->render('<twig:Ds:Pagination route="app_projects" :page="2" :totalPages="5" :pageList="[1, 2, 3, null, 5]" />');

        self::assertStringContainsString('<nav class="lp-pagination"', $html);
        self::assertStringContainsString('lp-pagination__ellipsis', $html);
        self::assertStringContainsString('aria-current="page"', $html);

        $single = $this->render('<twig:Ds:Pagination route="app_projects" :page="1" :totalPages="1" :pageList="[1]" />');

        self::assertStringNotContainsString('lp-pagination', $single);
    }

    public function test_the_catalog_lists_each_part_as_enforced_with_a_template(): void
    {
        self::bootKernel();
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $entries = [];
        foreach (new Catalog()->entries() as $entry) {
            $entries[$entry->name] = $entry;
        }

        foreach (['Dialog' => 'lp-dialog', 'Tabs' => 'lp-tabs', 'Pagination' => 'lp-pagination', 'Tooltip' => 'lp-tooltip'] as $name => $rootClass) {
            self::assertArrayHasKey($name, $entries);
            self::assertSame($rootClass, $entries[$name]->rootClass);
            self::assertTrue($entries[$name]->enforced);
            self::assertFileExists($projectDir.'/templates/'.$entries[$name]->template);
            self::assertFileExists($projectDir.'/templates/Module/DesignSystem/specimen/'.strtolower($name).'.html.twig');
            self::assertFileExists($projectDir.'/assets/styles/components/'.strtolower($name).'.css');
        }
    }
}
