<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

enum InboxItemKind: string
{
    case Question = 'question';
    case Todo = 'todo';
    case Review = 'review';
}
