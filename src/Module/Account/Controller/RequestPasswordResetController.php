<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Account\Form\ResetPasswordRequestFormType;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\PasswordResetEmailSender;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/forgot-password', name: 'app_forgot_password_request')]
class RequestPasswordResetController extends AppController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordResetEmailSender $passwordResetEmailSender,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            return $this->sendPasswordResetEmail($form->get('email')->getData());
        }

        return $this->renderFormResponse('@Account/reset_password/request.html.twig', $form);
    }

    private function sendPasswordResetEmail(string $emailFormData): Response
    {
        $user = $this->users->findOneByEmail($emailFormData);

        if (!$user instanceof User) {
            // Silently redirect — never reveal whether account exists.
            return $this->redirectToRoute('app_forgot_password_check_email');
        }

        if ($user->hasActivePasswordResetToken()) {
            // Token already issued and not yet expired — silent redirect to avoid enumeration.
            return $this->redirectToRoute('app_forgot_password_check_email');
        }

        try {
            $this->passwordResetEmailSender->send($user);
        } catch (\Throwable) {
            // Email sending failed; redirect silently so account existence is not revealed.
        }

        return $this->redirectToRoute('app_forgot_password_check_email');
    }
}
