<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Command;

use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;

/** Inserts a rival open ask after the handler's checks and before its flush. */
final class RivalAskBeforeFlush extends EntityManagerDecorator
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
        $this->wrapped->getConnection()->insert('inbox_asks', $this->rival);
        $this->wrapped->flush();
    }
}
