<?php

declare(strict_types=1);

namespace App\Module\Project;

/** The outbox event types this module produces, and the actors that cause them. */
final class ProjectEventType
{
    public const string RENAMED = 'project.renamed';

    /** A signed-in person. Board's CardReporter::Human writes the same value, which Project cannot import. */
    public const string ACTOR_HUMAN = 'human';

    private function __construct()
    {
    }
}
