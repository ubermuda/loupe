<?php

declare(strict_types=1);

namespace App\Module\Insights\Twig;

use App\Module\Insights\Entity\Proposal;
use App\Module\Insights\Form\DismissProposalFormType;
use App\Module\Insights\Form\DismissProposalRequest;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Builds the dismiss form of each proposal on the Reports page. A refused form comes back in place of a fresh one when its name matches. */
final class ReportsExtension extends AbstractExtension
{
    public function __construct(
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('insights_dismiss_form', $this->dismissForm(...))];
    }

    public function dismissForm(Proposal $proposal, ?FormView $refused = null): FormView
    {
        $name = DismissProposalFormType::nameFor($proposal);
        if (null !== $refused && $refused->vars['name'] === $name) {
            return $refused;
        }

        return $this->formFactory->createNamed($name, DismissProposalFormType::class, new DismissProposalRequest())->createView();
    }
}
