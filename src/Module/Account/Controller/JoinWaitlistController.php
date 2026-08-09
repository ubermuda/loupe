<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Command\JoinWaitlistCommand;
use App\Module\Account\Command\JoinWaitlistHandler;
use App\Module\Account\Form\WaitlistJoinFormType;
use App\Module\Account\Form\WaitlistJoinRequest;
use App\Module\Account\Service\RegistrationGate;
use App\Routing\PaywallExempt;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[PaywallExempt]
#[Route(
    '/waitlist',
    name: 'app_waitlist_join',
    methods: ['GET', 'POST'],
)]
final class JoinWaitlistController extends AppController
{
    public function __construct(
        private readonly RegistrationGate $gate,
        private readonly JoinWaitlistHandler $joinWaitlist,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,

        #[Autowire(service: 'limiter.waitlist_join')]
        private readonly RateLimiterFactory $waitlistJoinLimiter,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        // A waitlist only means something on an instance that intends to let
        // people in eventually. With sign-up switched off — or the install
        // wizard still pending — /register 404s, so redirecting there would
        // dead-end and collecting addresses would promise nothing.
        if (!$this->gate->allowsNewAccounts()) {
            $this->logger->info('account.waitlist.denied', ['path' => $request->getPathInfo()]);

            throw $this->createNotFoundException();
        }

        if ($this->gate->isOpen()) {
            return $this->redirectToRoute('app_register');
        }

        // Reached via the OAuth-at-cap redirect: the provider email was already
        // added to the waitlist by the resolver, so show the confirmation
        // directly instead of asking the visitor to submit the form again.
        if ($request->query->getBoolean('joined')) {
            return $this->render('@Account/registration/waitlist_joined.html.twig');
        }

        $form = $this->createForm(WaitlistJoinFormType::class, new WaitlistJoinRequest());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $limiter = $this->waitlistJoinLimiter->create($request->getClientIp() ?? 'unknown');
            if (!$limiter->consume(1)->isAccepted()) {
                $form->get('email')->addError(new FormError($this->translator->trans('account.waitlist.error.throttled')));

                return $this->renderFormResponse('@Account/registration/waitlist.html.twig', $form);
            }

            $data = $form->getData();
            assert($data instanceof WaitlistJoinRequest);

            ($this->joinWaitlist)(new JoinWaitlistCommand(
                $data->email ?: throw new \LogicException('Email is required after form validation.'),
            ));

            // Post/Redirect/Get: Turbo Drive throws "Form responses must redirect
            // to another location" for a direct 200 on a non-frame submission.
            // Always the same response, joined or already-listed, so the endpoint
            // enumerates nothing.
            return $this->redirectToRoute('app_waitlist_join', ['joined' => 1]);
        }

        return $this->renderFormResponse('@Account/registration/waitlist.html.twig', $form);
    }
}
