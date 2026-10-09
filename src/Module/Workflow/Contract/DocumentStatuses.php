<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** The statuses a document can have, as a rule names them. */
final class DocumentStatuses
{
    public const array ALL = ['in-review', 'approved', 'changes-requested', 'draft'];
}
