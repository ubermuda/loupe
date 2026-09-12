<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

/**
 * The roster is instance-wide, so the command carries no scope. It exists so
 * the handler keeps the one shape every other handler has.
 */
final readonly class ListAdvertisedToolsCommand
{
}
