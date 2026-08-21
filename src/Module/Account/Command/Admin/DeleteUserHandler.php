<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

use App\Exception\DomainErrors;
use App\Module\Account\Admin\AdminUserGuard;
use App\Module\Account\Deletion\AccountPurger;
use Psr\Log\LoggerInterface;

final readonly class DeleteUserHandler
{
    public function __construct(
        private AdminUserGuard $guard,
        private AccountPurger $purger,
        private LoggerInterface $logger,
    ) {
    }

    /** @throws DomainErrors when the guard refuses the deletion, or the typed confirmation does not match */
    public function __invoke(DeleteUserCommand $command): void
    {
        $target = $command->target;

        $this->guard->assertDeletable($target, $command->actor);

        // The typed confirmation is a rule, not a UI convention: a POST that
        // skips the modal must not delete anything. Case-insensitive because
        // User::$email is lowercased on write.
        if (mb_strtolower(trim($command->confirmEmail)) !== $target->email) {
            throw new DomainErrors(['confirmEmail' => 'account.admin.users.error.email_mismatch']);
        }

        // Read before the purge: the row, and the entity's id with it, is gone.
        $targetId = (string) ($target->id ?? throw new \LogicException('a persisted user always has an id'));

        $this->purger->purge($target);

        $this->logger->info('account.admin.user_deleted', [
            'targetId' => $targetId,
            'actorId' => (string) $command->actor->id,
        ]);
    }
}
