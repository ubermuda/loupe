<?php

declare(strict_types=1);

namespace App\Module\Billing\Twig;

use App\Module\Account\Entity\User;
use App\Module\Billing\Command\ShowSubscribeCommand;
use App\Module\Billing\Command\ShowSubscribeHandler;
use App\Module\Billing\Command\ShowSubscribeView;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The billing section of the account settings page. It is a component rather
 * than data passed in by the Account module so that module never has to know
 * the Billing module exists: the account template drops in `<twig:BillingSummary />`
 * and this class resolves the current user and their billing state itself.
 *
 * Props: none.
 */
#[AsTwigComponent(name: 'BillingSummary', template: 'components/BillingSummary.html.twig')]
final class BillingSummaryComponent
{
    /** Null for an anonymous render; the template then draws nothing. */
    public ?ShowSubscribeView $view = null;

    public function __construct(
        private readonly Security $security,
        private readonly ShowSubscribeHandler $showSubscribe,
    ) {
    }

    public function mount(): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $this->view = ($this->showSubscribe)(new ShowSubscribeCommand($user));
    }
}
