<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Module\DesignSystem\Catalog;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class ButtonComponentTest extends KernelTestCase
{
    private function render(string $template): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render();
    }

    public function test_a_button_carries_its_variant_size_and_attributes(): void
    {
        $html = $this->render('<twig:Ds:Button variant="primary" size="sm" type="submit" data-action="click->modal#open">Save</twig:Ds:Button>');

        self::assertStringContainsString('<button class="lp-btn lp-btn--primary lp-btn--sm"', $html);
        self::assertStringContainsString('type="submit"', $html);
        self::assertStringContainsString('data-action="click-&gt;modal#open"', $html);
        self::assertStringContainsString('>Save</button>', $html);
    }

    public function test_a_caller_class_is_appended(): void
    {
        $html = $this->render('<twig:Ds:Button variant="primary" class="lp-topbar__new-work">New</twig:Ds:Button>');

        self::assertStringContainsString('class="lp-btn lp-btn--primary lp-topbar__new-work"', $html);
    }

    public function test_an_href_renders_a_link(): void
    {
        $html = $this->render('<twig:Ds:Button variant="ghost" href="/projects">Back</twig:Ds:Button>');

        self::assertStringContainsString('<a class="lp-btn lp-btn--ghost" href="/projects">Back</a>', $html);
        self::assertStringNotContainsString('<button', $html);
    }

    public function test_a_boolean_attribute_passes_through(): void
    {
        $html = $this->render('<twig:Ds:Button disabled>Off</twig:Ds:Button>');

        self::assertMatchesRegularExpression('/<button[^>]* disabled/', $html);
    }

    public function test_a_null_or_false_attribute_is_left_out(): void
    {
        $html = $this->render('<twig:Ds:Button :disabled="false" :data-action="null" :data-turbo-frame="null">Go</twig:Ds:Button>');

        self::assertStringNotContainsString('disabled', $html);
        self::assertStringNotContainsString('data-action', $html);
        self::assertStringNotContainsString('data-turbo-frame', $html);
    }

    public function test_an_expression_attribute_is_rendered(): void
    {
        $html = $this->render('<twig:Ds:Button :disabled="true" :data-action="\'click->x#y\'">Go</twig:Ds:Button>');

        self::assertMatchesRegularExpression('/<button[^>]* disabled/', $html);
        self::assertStringContainsString('data-action="click-&gt;x#y"', $html);
    }

    public function test_the_catalog_lists_the_button_as_enforced(): void
    {
        $entries = new Catalog()->entries();

        self::assertCount(1, $entries);
        self::assertSame('lp-btn', $entries[0]->rootClass);
        self::assertTrue($entries[0]->enforced);
        self::assertFileExists(self::getContainer()->getParameter('kernel.project_dir').'/templates/'.$entries[0]->template);
    }
}
