<?php

declare(strict_types=1);

namespace App\Module\GitHub\Security;

use App\Module\Project\Entity\Project;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<'github_connection.manage', Project>
 */
final class GitHubConnectionVoter extends Voter
{
    public const string MANAGE = 'github_connection.manage';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE === $attribute && $subject instanceof Project;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (null !== $user && $subject->owner === $user) {
            return true;
        }

        $this->logger->info('github.connection_access_denied', [
            'projectId' => (string) $subject->id,
            'rule' => 'owner_only',
        ]);

        return false;
    }
}
