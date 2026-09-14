<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Command;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Inserts a rival open ask as the lock returns, which is what a rival that
 * committed while this call waited for the lock leaves behind.
 */
final class RivalAskOnLock extends EntityManagerDecorator
{
    /** @param array<string, string> $rival */
    public function __construct(
        EntityManagerInterface $wrapped,
        private readonly array $rival,
    ) {
        parent::__construct($wrapped);
    }

    #[\Override]
    public function lock(object $entity, LockMode|int $lockMode, \DateTimeInterface|int|null $lockVersion = null): void
    {
        $this->wrapped->lock($entity, $lockMode, $lockVersion);
        $this->wrapped->getConnection()->insert('inbox_asks', $this->rival);
    }
}
