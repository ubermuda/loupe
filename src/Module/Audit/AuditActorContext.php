<?php

declare(strict_types=1);

namespace App\Module\Audit;

final readonly class AuditActorContext
{
    /**
     * @param array<string, scalar|null> $context contributed by the environment
     *                                            rather than the caller, so a caller's own key of the same name wins
     */
    public function __construct(
        public ?AuditActorInterface $actor,
        public ?AuditCredentialInterface $credential,
        public string $channel,
        public array $context = [],
    ) {
    }
}
