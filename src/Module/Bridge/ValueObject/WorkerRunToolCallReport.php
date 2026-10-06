<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** One tool call of a run, as the bridge reports it. */
final readonly class WorkerRunToolCallReport
{
    /** @param list<string> $signatures */
    public function __construct(
        public int $seq,
        public string $tool,
        public \DateTimeImmutable $startedAt,
        public ?int $durationMs,
        public ?bool $isError,
        public bool $inSubagent,
        public ?string $backgroundId,
        public ?string $waitsOn,
        public array $signatures,
        public ?string $fullText,
    ) {
    }
}
