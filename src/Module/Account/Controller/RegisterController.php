<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Command\RegisterUserCommand;
use App\Module\Account\Command\RegisterUserHandler;
use App\Module\Account\Form\RegistrationFormType;
use App\Module\Account\Form\RegistrationRequest;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/register', name: 'app_register')]
class RegisterController extends AppController
{
    public function __construct(
        private readonly RegisterUserHandler $registerUser,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $form = $this->createForm(RegistrationFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            assert($data instanceof RegistrationRequest);

            try {
                $user = ($this->registerUser)(new RegisterUserCommand(
                    username: (string) $data->username,
                    fullName: (string) $data->fullName,
                    email: $data->email ?: throw new \LogicException('Email is required after form validation.'),
                    plainPassword: (string) $data->plainPassword,
                ));
            } catch (DomainErrors $e) {
                foreach ($e->errors as $field => $messageKey) {
                    $form->get($field)->addError(new FormError($this->translator->trans($messageKey)));
                }

                return $this->renderFormResponse('@Account/registration/register.html.twig', $form);
            }

            $request->getSession()->set('registration_email', $user->email);

            return $this->redirectToRoute('app_register_check_email');
        }

        return $this->renderFormResponse('@Account/registration/register.html.twig', $form);
    }
}
