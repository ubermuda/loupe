<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Module\DesignSystem\Catalog;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Twig\Environment;

final class FormComponentsTest extends KernelTestCase
{
    /** @param array<string, mixed> $context */
    private function render(string $template, array $context = []): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render($context);
    }

    public function test_an_input_carries_its_class_and_attributes(): void
    {
        $html = $this->render('<twig:Ds:Input name="q" class="lp-filter-input" mono />');

        self::assertStringContainsString('<input class="lp-input lp-input--mono lp-filter-input"', $html);
        self::assertStringContainsString('name="q"', $html);
    }

    public function test_a_null_attribute_is_left_out(): void
    {
        $html = $this->render('<twig:Ds:Input :placeholder="null" :disabled="false" />');

        self::assertStringNotContainsString('placeholder', $html);
        self::assertStringNotContainsString('disabled', $html);
    }

    public function test_a_select_wraps_its_options(): void
    {
        $html = $this->render('<twig:Ds:Select name="s"><option>A</option></twig:Ds:Select>');

        self::assertStringContainsString('<select class="lp-input lp-select"', $html);
        self::assertStringContainsString('<option>A</option></select>', $html);
    }

    public function test_a_textarea_wraps_its_value(): void
    {
        $html = $this->render('<twig:Ds:Textarea rows="3">Text</twig:Ds:Textarea>');

        self::assertStringContainsString('<textarea class="lp-input lp-textarea"', $html);
        self::assertStringContainsString('>Text</textarea>', $html);
    }

    public function test_a_label_renders_the_tag_it_is_given(): void
    {
        self::assertStringContainsString('<label class="lp-label" for="x">Name</label>', $this->render('<twig:Ds:Label for="x">Name</twig:Ds:Label>'));
        self::assertStringContainsString('<legend class="lp-label">Pick</legend>', $this->render('<twig:Ds:Label as="legend">Pick</twig:Ds:Label>'));
    }

    public function test_a_hint_is_a_paragraph_by_default(): void
    {
        self::assertStringContainsString('<p class="lp-form-hint" id="h">Help</p>', $this->render('<twig:Ds:Hint id="h">Help</twig:Ds:Hint>'));
        self::assertStringContainsString('<span class="lp-form-hint">Help</span>', $this->render('<twig:Ds:Hint as="span">Help</twig:Ds:Hint>'));
    }

    public function test_the_error_list_shows_a_field_error_or_its_body(): void
    {
        self::bootKernel();
        $form = static::getContainer()->get(FormFactoryInterface::class)->createBuilder(FormType::class, null, ['csrf_protection' => false])->add('name', TextType::class)->getForm();
        $form->get('name')->addError(new \Symfony\Component\Form\FormError('Too short'));
        $view = $form->createView();

        $withField = $this->render('<twig:Ds:FieldErrors :fieldView="form.name" data-field-errors="name" />', ['form' => $view]);
        $withBody = $this->render('<twig:Ds:FieldErrors><ul><li>Body</li></ul></twig:Ds:FieldErrors>');

        self::assertStringContainsString('<div class="lp-field-errors" data-field-errors="name">', $withField);
        self::assertStringContainsString('Too short', $withField);
        self::assertStringContainsString('<div class="lp-field-errors"><ul><li>Body</li></ul></div>', $withBody);
    }

    public function test_a_form_field_draws_the_label_widget_hint_and_errors_of_a_form_view(): void
    {
        self::bootKernel();
        $form = static::getContainer()->get(FormFactoryInterface::class)->createBuilder(FormType::class, null, ['csrf_protection' => false])->add('name', TextType::class)->getForm();
        $html = $this->render('<twig:Ds:FormField :fieldView="form.name" hintText="Shown on the page" :widgetAttr="{class: \'extra\', placeholder: \'P\'}" />', ['form' => $form->createView()]);

        self::assertStringContainsString('<div class="lp-form-field">', $html);
        self::assertStringContainsString('<label class="lp-label required" for="form_name">Name</label>', $html);
        self::assertStringContainsString('class="lp-input extra"', $html);
        self::assertStringContainsString('placeholder="P"', $html);
        self::assertStringContainsString('<p class="lp-form-hint">Shown on the page</p>', $html);
        self::assertStringContainsString('<div class="lp-field-errors"></div>', $html);
    }

    public function test_a_form_field_without_a_view_wraps_its_body(): void
    {
        $html = $this->render('<twig:Ds:FormField as="fieldset" class="extra"><twig:Ds:Label as="legend">L</twig:Ds:Label></twig:Ds:FormField>');

        self::assertStringContainsString('<fieldset class="lp-form-field extra">', $html);
        self::assertStringContainsString('<legend class="lp-label">L</legend>', $html);
    }

    public function test_the_catalog_lists_every_form_part_as_enforced(): void
    {
        $roots = [];
        foreach (new Catalog()->entries() as $entry) {
            $roots[$entry->name] = $entry;
        }

        foreach (['Input' => 'lp-input', 'Select' => 'lp-select', 'Textarea' => 'lp-textarea', 'Label' => 'lp-label', 'FormField' => 'lp-form-field', 'FieldErrors' => 'lp-field-errors', 'Hint' => 'lp-form-hint'] as $name => $rootClass) {
            self::assertArrayHasKey($name, $roots);
            self::assertSame($rootClass, $roots[$name]->rootClass);
            self::assertTrue($roots[$name]->enforced);
            self::assertFileExists(self::getContainer()->getParameter('kernel.project_dir').'/templates/'.$roots[$name]->template);
        }
    }
}
