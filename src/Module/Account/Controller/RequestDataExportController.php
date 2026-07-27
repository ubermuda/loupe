<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Command\RequestDataExportCommand;
use App\Module\Account\Command\RequestDataExportHandler;
use App\Module\Account\Entity\User;
use App\Routing\PaywallExempt;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('request-data-export')]
#[PaywallExempt]
#[Route(
    '/account/exports',
    name: 'app_account_export_request',
    methods: ['POST'],
)]
class RequestDataExportController extends AppController
{
    public function __construct(
        private readonly RequestDataExportHandler $handler,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        try {
            ($this->handler)(new RequestDataExportCommand($user));
            $this->addFlash('success', $this->translator->trans('account.settings.export.flash.requested'));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }
        }

        return $this->redirectToRoute('app_account_settings');
    }
}
