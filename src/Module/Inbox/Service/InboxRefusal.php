<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

/**
 * A refusal decided inside a transaction. It leaves the transaction as a value,
 * because a DomainErrors thrown inside one closes the EntityManager.
 */
final readonly class InboxRefusal
{
    public function __construct(
        public string $field,
        public string $key,
    ) {
    }
}
