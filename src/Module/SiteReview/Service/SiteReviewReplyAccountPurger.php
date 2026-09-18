<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Service;

use App\Module\Account\Deletion\AccountDataPurgerInterface;
use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use Doctrine\DBAL\Connection;

final readonly class SiteReviewReplyAccountPurger implements AccountDataPurgerInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function deletionOrder(): int
    {
        return 20;
    }

    #[\Override]
    public function purge(User $user, AccountDeletionCleanup $cleanup): void
    {
        $this->connection->executeStatement('DELETE FROM site_review_replies WHERE author_id = :user', ['user' => (string) $user->id]);
    }
}
