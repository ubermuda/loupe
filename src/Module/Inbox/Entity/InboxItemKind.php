<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

enum InboxItemKind: string
{
    case Question = 'question';
    case Todo = 'todo';
    case Review = 'review';
    /** Loupe opens it for a card that waits for a person. It always blocks and takes no answer. */
    case Wait = 'wait';
    /** Loupe opens it for a project-level fact. It has no card, takes no answer and never blocks. */
    case Notice = 'notice';
}
