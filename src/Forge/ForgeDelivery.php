<?php

declare(strict_types=1);

namespace App\Forge;

/**
 * One fact an adapter read out of a verified delivery, in the neutral
 * vocabulary of ForgeEventType.
 *
 * It carries identifiers alone. A review note, a commit message and a branch
 * name are text a person wrote, and text a person wrote must never reach an
 * agent as a directive.
 */
final readonly class ForgeDelivery
{
    /**
     * @param ForgeEventType::* $type
     * @param non-empty-string  $forge      the forge's own slug, such as `github`. A string rather
     *                                      than an enum, so this package names no Loupe type
     * @param string            $repository the path the pull request lives under, such as `owner/repo`.
     *                                      GitLab nests groups, so it may hold more than one slash
     * @param ?int              $number     null on REPOSITORY_MOVED, which names no single pull request
     * @param ?string           $movedTo    the new repository path, on REPOSITORY_MOVED alone
     */
    public function __construct(
        public string $type,
        public string $forge,
        public string $repository,
        public ?int $number = null,
        public ?string $movedTo = null,
    ) {
    }
}
