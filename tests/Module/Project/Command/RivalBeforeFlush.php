<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Command;

use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Inserts a rival row after the handler's checks and before its flush, which is
 * the window a concurrent request uses. The unique index then raises a real error.
 */
final class RivalBeforeFlush extends EntityManagerDecorator
{
    /** @param array<string, string> $rival */
    public function __construct(
        EntityManagerInterface $wrapped,
        private readonly array $rival,
    ) {
        parent::__construct($wrapped);
    }

    #[\Override]
    public function flush(): void
    {
        $this->wrapped->getConnection()->insert('projects', $this->rival);
        $this->wrapped->flush();
    }
}
