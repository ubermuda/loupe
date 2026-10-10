<?php

declare(strict_types=1);

namespace App\Module\Inbox\Twig;

use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\Repository\InboxReviewRepository;
use App\Module\Inbox\Service\InboxAvailability;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Review\Entity\Document;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Review must not import Inbox, so the review page calls this function for the cards that wait on a document. */
final class DocumentWaitLineExtension extends AbstractExtension
{
    public function __construct(
        private readonly InboxAvailability $inbox,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly InboxCardWatchRepository $inboxCardWatches,
        private readonly InboxReviewRepository $inboxReviews,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('inbox_document_wait_line', $this->waitLine(...), ['needs_environment' => true, 'is_safe' => ['html']]),
        ];
    }

    public function waitLine(Environment $twig, Document $document): string
    {
        if (!$this->inbox->isEnabled() || !$this->authorization->isGranted(ProjectVoter::VIEW, $document->project)) {
            return '';
        }

        // Keyed by card number, so a card that waits and also holds a review item shows once.
        $waiting = [];
        foreach ($this->inboxCardWatches->findOpenForDocument($document) as $watch) {
            $waiting[$watch->cardNumber] ??= $watch->item->number;
        }
        foreach ($this->inboxReviews->findOpenForDocument($document) as $review) {
            foreach ($review->item->cards as $link) {
                $waiting[$link->card->number] ??= $review->item->number;
            }
        }
        if ([] === $waiting) {
            return '';
        }
        ksort($waiting);

        return $twig->render('@Inbox/_document_wait_line.html.twig', [
            'project' => $document->project,
            'waiting' => $waiting,
        ]);
    }
}
