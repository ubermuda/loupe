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
