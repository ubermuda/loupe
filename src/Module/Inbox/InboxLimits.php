<?php

declare(strict_types=1);

namespace App\Module\Inbox;

/** The size limits on what an agent hands to the inbox. The handlers enforce them, and the tool schemas publish them. */
final class InboxLimits
{
    public const int MAX_ITEMS_PER_CALL = 20;

    public const int MAX_OPTIONS = 20;

    /** Per option, in characters as sent. An inbox_list row carries every option. */
    public const int MAX_OPTION_LENGTH = 500;

    /** Per item, and per kind of link: cards and documents each. */
    public const int MAX_LINKS = 20;

    public const int MAX_BODY_LENGTH = 20000;

    /** Measured after a later call appends its context to the open ask. */
    public const int MAX_CONTEXT_LENGTH = 10000;

    public const int MAX_WITHDRAW_REASON_LENGTH = 2000;
}
