<?php

declare(strict_types=1);

namespace App\Module\Inbox\Security;

use App\Mercure\MercureTopicAuthorizerInterface;
use App\Mercure\UserTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Inbox\Service\InboxAvailability;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Only the user an inbox topic names may listen on it. No voter covers a user
 * acting on their own account, so the ids are compared here.
 */
final readonly class InboxTopicAuthorizer implements MercureTopicAuthorizerInterface
{
    public function __construct(
        private UserTopicBuilder $topics,
        private InboxAvailability $inbox,
        private Security $security,
    ) {
    }

    #[\Override]
    public function mayCurrentUserSubscribe(string $topic): ?bool
    {
        $userId = $this->topics->userIdFromInboxTopic($topic);
        if (null === $userId) {
            return null;
        }

        $user = $this->security->getUser();

        return $user instanceof User
            && null !== $user->id
            && $user->id->equals($userId)
            && $this->inbox->isEnabled();
    }
}
