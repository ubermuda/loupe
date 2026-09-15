<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

/** A question closes with an answer. A to-do closes when the owner marks it done or declines it. */
enum InboxItemKind: string
{
    case Question = 'question';
    case Todo = 'todo';
}
