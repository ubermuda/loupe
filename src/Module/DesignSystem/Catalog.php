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
                sizes: ['sm', 'lg'],
                group: 'core',
                summary: 'Any action or link that looks like a button.',
                element: 'button',
            ),
            new ComponentEntry(
                name: 'Flash',
                rootClass: 'lp-flash',
                template: 'components/Ds/Flash.html.twig',
                variants: ['success', 'error', 'warning', 'info'],
                states: [],
                enforced: true,
                group: 'core',
                summary: 'A short message about the result of an action.',
                element: 'div',
                dotClass: 'lp-flash__dot',
                contentClass: 'lp-flash__message',
            ),
            new ComponentEntry(
                name: 'EmptyState',
                rootClass: 'lp-empty-state',
                template: 'components/Ds/EmptyState.html.twig',
                variants: [],
                states: [],
                enforced: true,
                group: 'core',
                summary: 'The block that tells a user a list or page has no content yet.',
                element: 'div',
            ),
            new ComponentEntry(
                name: 'Badge',
                rootClass: 'lp-badge',
                template: 'components/Ds/Badge.html.twig',
                variants: ['in-review', 'draft', 'approved', 'changes-requested'],
                states: [],
                enforced: true,
                group: 'core',
                summary: 'A small label for the review status of a document.',
                element: 'span',
            ),
            new ComponentEntry(
                name: 'Tag',
                rootClass: 'lp-tag',
                template: 'components/Ds/Tag.html.twig',
                variants: ['neutral', 'lime', 'purple', 'green', 'amber', 'red', 'teal', 'sky', 'blue', 'indigo', 'pink', 'orange'],
                states: [],
                enforced: true,
                group: 'core',
                summary: 'A small coloured label for a category or a tone.',
                element: 'span',
            ),
            new ComponentEntry(
                name: 'StatusChip',
                rootClass: 'lp-status-chip',
                template: 'components/Ds/StatusChip.html.twig',
                variants: ['pending', 'addressed', 'resolved', 'ok', 'failed', 'neutral'],
                states: ['reason'],
                enforced: true,
                group: 'core',
                summary: 'A chip that names a state, with an optional reason in a tooltip.',
                element: 'span',
                dotClass: 'lp-status-chip__dot',
            ),
            new ComponentEntry(
                name: 'Dialog',
                rootClass: 'lp-dialog',
                template: 'components/Ds/Dialog.html.twig',
                variants: ['document', 'search'],
                states: ['open'],
                enforced: true,
                group: 'core',
                summary: 'A modal window over the page, opened by the modal controller.',
                element: 'dialog',
            ),
            new ComponentEntry(
                name: 'Tabs',
                rootClass: 'lp-tabs',
                template: 'components/Ds/Tabs.html.twig',
                variants: [],
                states: ['hover', 'selected'],
                enforced: true,
                group: 'core',
                summary: 'A row of tabs that switches between views of one page.',
                element: 'nav',
            ),
            new ComponentEntry(
                name: 'Pagination',
                rootClass: 'lp-pagination',
                template: 'components/Ds/Pagination.html.twig',
                variants: [],
                states: ['current', 'disabled'],
                enforced: true,
                group: 'core',
                summary: 'The previous, next and page-number links of a long list.',
                element: 'nav',
            ),
            new ComponentEntry(
                name: 'Tooltip',
                rootClass: 'lp-tooltip',
                template: 'components/Ds/Tooltip.html.twig',
                variants: [],
                states: ['visible'],
                enforced: true,
                group: 'core',
                summary: 'A short label that shows over an element on hover or focus.',
                element: 'span',
            ),
        ];
    }
}
