<?php

declare(strict_types=1);

namespace App\Observability;

use Sentry\State\HubInterface;
use Sentry\Tracing\SpanContext;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Times work that runs before the Sentry transaction exists. With no hub span,
 * the interval waits here until RequestTimelineListener attaches it.
 */
final class RequestTimeline implements ResetInterface
{
    /** @var list<RecordedSpan> */
    private array $recorded = [];

    public function __construct(
        private readonly HubInterface $hub,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function record(string $op, float $start, float $end, ?string $description = null, array $data = []): void
    {
        $this->recorded[] = new RecordedSpan($op, $start, $end, $description, $data);
    }

    /**
     * @template T
     *
     * @param \Closure(): T        $work
     * @param array<string, mixed> $data
     *
     * @return T
     */
    public function span(string $op, \Closure $work, ?string $description = null, array $data = []): mixed
    {
        $span = $this->hub->getSpan()?->startChild(
            SpanContext::make()->setOp($op)->setDescription($description)->setData($data),
        );

        if (null !== $span) {
            try {
                return $work();
            } finally {
                $span->finish();
            }
        }

        $start = microtime(true);

        try {
            return $work();
        } finally {
            $this->record($op, $start, microtime(true), $description, $data);
        }
    }

    /**
     * @return list<RecordedSpan>
     */
    public function drain(): array
    {
        $recorded = $this->recorded;
        $this->recorded = [];

        return $recorded;
    }

    #[\Override]
    public function reset(): void
    {
        $this->recorded = [];
    }
}
