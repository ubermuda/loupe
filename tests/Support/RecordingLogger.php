<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * Logger that records every entry instead of writing it, so tests can assert
 * which domain events a handler logged and with what context.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
