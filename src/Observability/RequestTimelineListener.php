<?php

declare(strict_types=1);

namespace App\Observability;

use Sentry\State\HubInterface;
use Sentry\Tracing\SpanContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The Sentry bundle starts the transaction on kernel.request at priority 4, so
 * attach runs just after it and recordBoot runs before every other listener.
 */
final readonly class RequestTimelineListener
{
    public function __construct(
        private RequestTimeline $timeline,
        private HubInterface $hub,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 4096)]
    public function recordBoot(RequestEvent $event): void
    {
        $requestTime = $event->getRequest()->server->get('REQUEST_TIME_FLOAT');

        if (!$event->isMainRequest() || !is_numeric($requestTime)) {
            return;
        }

        $this->timeline->record('app.boot', (float) $requestTime, microtime(true));
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 3)]
    public function attach(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $parent = $this->hub->getSpan();

        foreach ($this->timeline->drain() as $recorded) {
            $parent?->startChild(
                SpanContext::make()
                    ->setOp($recorded->op)
                    ->setDescription($recorded->description)
                    ->setData($recorded->data)
                    ->setStartTimestamp($recorded->start),
            )->finish($recorded->end);
        }
    }
}
