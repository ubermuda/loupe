<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Repository\WaitlistEntryRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class JoinWaitlistHandler
{
    public function __construct(
        private WaitlistEntryRepository $entries,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(JoinWaitlistCommand $command): void
    {
        if (null !== $this->entries->findOneByEmail($command->email)) {
            $this->logger->info('account.waitlist.duplicate_join', ['email' => $command->email]);

            return;
        }

        try {
            $this->em->persist(new WaitlistEntry($command->email));
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Two concurrent joins with the same email — the row exists, which
            // is the desired end state; silent like the duplicate branch above.
            return;
        }

        $this->logger->info('account.waitlist.joined', ['email' => $command->email]);
    }
}
