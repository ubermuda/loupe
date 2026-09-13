<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Service\BoardAvailability;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers a rule health report with 404 board_disabled while the board is off.
 * It runs on the request, after the firewall, so the body is never read or
 * validated: a report the board cannot show gets the same answer every time.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 2)]
final readonly class RefuseBridgeRuleReportsWhileBoardDisabled
{
    public function __construct(
        private BoardAvailability $board,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (RateLimitAgentBridgeRuleReports::ROUTE !== $event->getRequest()->attributes->get('_route')) {
            return;
        }

        if (!$this->board->isEnabled()) {
            $event->setResponse(new JsonResponse(['error' => 'board_disabled'], JsonResponse::HTTP_NOT_FOUND));
        }
    }
}
