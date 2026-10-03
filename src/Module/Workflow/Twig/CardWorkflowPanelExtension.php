<?php

declare(strict_types=1);

namespace App\Module\Workflow\Twig;

use App\Module\Board\Entity\Card;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Workflow\Service\CardWorkflowPanelBuilder;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Board must not import Workflow, so the card page calls this function instead. */
final class CardWorkflowPanelExtension extends AbstractExtension
{
    public function __construct(
        private readonly CardWorkflowPanelBuilder $panels,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('workflow_card_panel', $this->cardPanel(...), ['needs_environment' => true, 'is_safe' => ['html']]),
        ];
    }

    public function cardPanel(Environment $twig, Card $card): string
    {
        if (null === $card->id || !$this->authorization->isGranted(ProjectVoter::VIEW, $card->project)) {
            return '';
        }

        $panel = $this->panels->build($card);

        return $panel->isEmpty() ? '' : $twig->render('@Workflow/_card_workflow_panel.html.twig', ['panel' => $panel]);
    }
}
