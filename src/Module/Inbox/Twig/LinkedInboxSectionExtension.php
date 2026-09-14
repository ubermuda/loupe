<?php

declare(strict_types=1);

namespace App\Module\Inbox\Twig;

use App\Module\Board\Entity\Card;
use App\Module\Inbox\Command\ShowLinkedInboxItemsCommand;
use App\Module\Inbox\Command\ShowLinkedInboxItemsHandler;
use App\Module\Inbox\Controller\ShowInboxController;
use App\Module\Inbox\Service\InboxAvailability;
use App\Module\Inbox\Service\InboxLinkedPage;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Review\Entity\Document;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The inbox section of a card page and of a document page. Board and Review
 * must not import Inbox, so their templates call these functions instead.
 */
final class LinkedInboxSectionExtension extends AbstractExtension
{
    public function __construct(
        private readonly InboxAvailability $inbox,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly ShowLinkedInboxItemsHandler $showLinkedItems,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('inbox_card_section', $this->cardSection(...), ['needs_environment' => true, 'is_safe' => ['html']]),
            new TwigFunction('inbox_document_section', $this->documentSection(...), ['needs_environment' => true, 'is_safe' => ['html']]),
        ];
    }

    public function cardSection(Environment $twig, Card $card): string
    {
        return $this->section($twig, $card->project, InboxLinkedPage::Card, $card->id);
    }

    /** @param int|null $versionNumber the older version the page shows, or null on the current one */
    public function documentSection(Environment $twig, Document $document, ?int $versionNumber = null): string
    {
        return $this->section($twig, $document->project, InboxLinkedPage::Document, $document->id, $versionNumber);
    }

    private function section(Environment $twig, Project $project, InboxLinkedPage $page, ?Uuid $targetId, ?int $versionNumber = null): string
    {
        if (null === $targetId || !$this->inbox->isEnabled() || !$this->authorization->isGranted(ProjectVoter::VIEW, $project)) {
            return '';
        }

        $view = ($this->showLinkedItems)(new ShowLinkedInboxItemsCommand($project, $page, $targetId, $versionNumber));
        if ($view->isEmpty()) {
            return '';
        }

        // A response refused elsewhere forwards here with its form, so the section shows the refusal.
        $refused = $this->requestStack->getCurrentRequest()?->attributes->get(ShowInboxController::REFUSED_FORM);

        return $twig->render('@Inbox/_linked_inbox_section.html.twig', [
            'inbox' => $view,
            'refusedForm' => $refused instanceof FormView ? $refused : null,
        ]);
    }
}
