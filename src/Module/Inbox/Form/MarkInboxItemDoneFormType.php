<?php

declare(strict_types=1);

namespace App\Module\Inbox\Form;

use App\Module\Inbox\Entity\InboxItem;
use Symfony\Component\Form\AbstractType;

/**
 * A real form rather than a CSRF attribute: the control repeats on every to-do
 * of a page the owner keeps open, so a stale token must show an error in place
 * rather than a 403.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class MarkInboxItemDoneFormType extends AbstractType
{
    public static function nameFor(InboxItem $item): string
    {
        return 'inbox_done_'.$item->id;
    }
}
