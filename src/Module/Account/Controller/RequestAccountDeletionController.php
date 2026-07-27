<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Command\RequestAccountDeletionCommand;
use App\Module\Account\Command\RequestAccountDeletionHandler;
use App\Module\Account\Entity\User;
use App\Routing\PaywallExempt;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('request-account-deletion')]
#[PaywallExempt]
#[Route(
    '/account/delete/request',
    name: 'app_account_delete_request',
    methods: ['POST'],
)]
class RequestAccountDeletionController extends AppController
{
    public function __construct(
        private readonly RequestAccountDeletionHandler $handler,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        ($this->handler)(new RequestAccountDeletionCommand($user));

        $this->addFlash('success', $this->translator->trans('account.delete.flash.requested'));

        return $this->redirectToRoute('app_account_settings');
    }
}
