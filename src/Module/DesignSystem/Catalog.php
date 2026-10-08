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
            new ComponentEntry(
                name: 'Flash',
                rootClass: 'lp-flash',
                template: 'components/Ds/Flash.html.twig',
                variants: ['success', 'error', 'warning', 'info'],
                states: [],
                enforced: true,
            ),
            new ComponentEntry(
                name: 'EmptyState',
                rootClass: 'lp-empty-state',
                template: 'components/Ds/EmptyState.html.twig',
                variants: [],
                states: [],
                enforced: true,
            ),
            new ComponentEntry(
                name: 'Badge',
                rootClass: 'lp-badge',
                template: 'components/Ds/Badge.html.twig',
                variants: ['in-review', 'draft', 'approved', 'changes-requested'],
                states: [],
                enforced: true,
            ),
            new ComponentEntry(
                name: 'Tag',
                rootClass: 'lp-tag',
                template: 'components/Ds/Tag.html.twig',
                variants: ['neutral', 'lime', 'purple', 'green', 'amber', 'red', 'teal', 'sky', 'blue', 'indigo', 'pink', 'orange'],
                states: [],
                enforced: true,
            ),
            new ComponentEntry(
                name: 'StatusChip',
                rootClass: 'lp-status-chip',
                template: 'components/Ds/StatusChip.html.twig',
                variants: ['pending', 'addressed', 'resolved', 'ok', 'failed', 'neutral'],
                states: ['reason'],
                enforced: true,
            ),
            new ComponentEntry(
                name: 'Dialog',
                rootClass: 'lp-dialog',
                template: 'components/Ds/Dialog.html.twig',
                variants: ['document', 'search'],
                states: ['open'],
                enforced: true,
            ),
            new ComponentEntry(
                name: 'Tabs',
                rootClass: 'lp-tabs',
                template: 'components/Ds/Tabs.html.twig',
                variants: [],
                states: ['hover', 'selected'],
                enforced: true,
            ),
            new ComponentEntry(
                name: 'Pagination',
                rootClass: 'lp-pagination',
                template: 'components/Ds/Pagination.html.twig',
                variants: [],
                states: ['current', 'disabled'],
                enforced: true,
            ),
            new ComponentEntry(
                name: 'Tooltip',
                rootClass: 'lp-tooltip',
                template: 'components/Ds/Tooltip.html.twig',
                variants: [],
                states: ['visible'],
                enforced: true,
            ),
        ];
    }
}
