<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Command\JoinWaitlistCommand;
use App\Module\Account\Command\JoinWaitlistHandler;
use App\Module\Account\Form\WaitlistJoinFormType;
use App\Module\Account\Form\WaitlistJoinRequest;
use App\Module\Account\Service\RegistrationGate;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/waitlist', name: 'app_waitlist_join', methods: ['GET', 'POST'])]
final class JoinWaitlistController extends AppController
{
    public function __construct(
        private readonly RegistrationGate $gate,
        private readonly JoinWaitlistHandler $joinWaitlist,
        private readonly TranslatorInterface $translator,

        #[Autowire(service: 'limiter.waitlist_join')]
        private readonly RateLimiterFactory $waitlistJoinLimiter,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($this->gate->isOpen()) {
            return $this->redirectToRoute('app_register');
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

            // Always the same response, joined-or-already-listed — no enumeration.
            return $this->render('@Account/registration/waitlist_joined.html.twig');
        }

        return $this->renderFormResponse('@Account/registration/waitlist.html.twig', $form);
    }
}
