<?php

declare(strict_types=1);

namespace App\Module\Board\Security;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Project\Entity\Project;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Adding and reordering take the project, and every other column change takes
 * the column, which walks up to its project.
 *
 * @extends Voter<'column.manage', Project|BoardColumn>
 */
final class BoardColumnVoter extends Voter
{
    public const string MANAGE = 'column.manage';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE === $attribute
            && ($subject instanceof Project || $subject instanceof BoardColumn);
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $project = $subject instanceof BoardColumn ? $subject->project : $subject;

        if ($project->owner === $token->getUser()) {
            return true;
        }

        $this->logger->info('board.column_manage_denied', [
            'projectId' => (string) $project->id,
            'rule' => 'owner_only',
        ]);

        return false;
    }
}
