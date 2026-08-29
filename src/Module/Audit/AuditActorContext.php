<?php

declare(strict_types=1);

namespace App\Module\Audit;

final readonly class AuditActorContext
{
    public function __construct(
        public ?AuditActorInterface $actor,
        public ?AuditCredentialInterface $credential,
        public string $channel,
    ) {
    }
}
