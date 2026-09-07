<?php

declare(strict_types=1);

namespace App\Security;

use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

/**
 * A subject that belongs to one project, and that an MCP token's project
 * binding is therefore checked against.
 *
 * The walk from the subject to its project differs per entity: most hold the
 * project directly, a review comment reaches it through its version and its
 * document, and a project is its own. Each implementation absorbs its own walk,
 * so McpBoundProjectVoter votes on every module's subjects without importing
 * one of them.
 */
interface ProjectScopedSubject
{
    /** Null until the subject is flushed, which is what Doctrine assigns it. */
    public ?Uuid $id { get; }

    /** The project the subject belongs to. */
    public function scopedProject(): Project;

    /**
     * The name this kind of subject goes by, such as `document` or `card`. It
     * pairs the subject with the attributes that accept it, and labels the
     * audit record a refusal writes.
     */
    public function scopedSubjectType(): string;
}
