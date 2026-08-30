<?php

declare(strict_types=1);

namespace App\Audit\EventListener;

use App\Audit\AuditChannel;
use App\Audit\AuditContext;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A CLI process has neither a request nor a security token, which is all the
 * actor provider has to go on; without this it can only record `system`.
 */
#[AsEventListener]
final readonly class SetConsoleAuditChannelListener
{
    public function __construct(
        private AuditContext $auditContext,
    ) {
    }

    public function __invoke(ConsoleCommandEvent $event): void
    {
        $this->auditContext->channel = AuditChannel::Console;
    }
}
