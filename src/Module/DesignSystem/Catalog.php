<?php

declare(strict_types=1);

namespace App\Module\DesignSystem;

/**
 * Every building block of the design system, in one place. A child card adds
 * the entry for its component in the same branch that adds the component.
 */
final readonly class Catalog
{
    /** @return list<ComponentEntry> */
    public function entries(): array
    {
        return [
            new ComponentEntry(
                name: 'Button',
                rootClass: 'lp-btn',
                template: 'components/Ds/Button.html.twig',
                variants: ['primary', 'inverse', 'outline', 'success', 'danger', 'ghost', 'danger-ghost', 'on-card', 'icon', 'compact', 'open'],
                states: ['hover', 'active', 'disabled'],
                enforced: true,
            ),
            new ComponentEntry(
                name: 'Input',
                rootClass: 'lp-input',
                template: 'components/Ds/Input.html.twig',
                variants: ['mono'],
                states: ['focus', 'disabled'],
                enforced: true,
            ),
            new ComponentEntry(
                name: 'Select',
                rootClass: 'lp-select',
                template: 'components/Ds/Select.html.twig',
                variants: [],
                states: ['focus', 'disabled'],
                enforced: true,
            ),
            new ComponentEntry(
                name: 'Textarea',
                rootClass: 'lp-textarea',
                template: 'components/Ds/Textarea.html.twig',
                variants: [],
                states: ['focus', 'disabled'],
                enforced: true,
            ),
            new ComponentEntry(
                name: 'Label',
                rootClass: 'lp-label',
                template: 'components/Ds/Label.html.twig',
                variants: [],
                states: [],
                enforced: true,
            ),
            new ComponentEntry(
                name: 'FormField',
                rootClass: 'lp-form-field',
                template: 'components/Ds/FormField.html.twig',
                variants: [],
                states: [],
                enforced: true,
            ),
            new ComponentEntry(
                name: 'FieldErrors',
                rootClass: 'lp-field-errors',
                template: 'components/Ds/FieldErrors.html.twig',
                variants: [],
                states: [],
                enforced: true,
            ),
            new ComponentEntry(
                name: 'Hint',
                rootClass: 'lp-form-hint',
                template: 'components/Ds/Hint.html.twig',
                variants: [],
                states: [],
                enforced: true,
            ),
        ];
    }
}
