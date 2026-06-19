<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Account\Form\ChangePasswordFormType;
use App\Module\Account\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(
    '/forgot-password/reset/{token}',
    name: 'app_reset_password',
    defaults: ['token' => null],
)]
class ResetPasswordController extends AppController
{
    private const string SESSION_TOKEN_KEY = '_reset_password_token';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TranslatorInterface $translator,
        private readonly UserRepository $users,
    ) {
    }

    public function __invoke(Request $request, ?string $token = null): Response
    {
        if (null !== $token) {
            $request->getSession()->set(self::SESSION_TOKEN_KEY, $token);

            return $this->redirectToRoute('app_reset_password');
        }

        $token = $request->getSession()->get(self::SESSION_TOKEN_KEY);
        if (!is_string($token) || '' === $token) {
            throw $this->createNotFoundException('No reset password token found in the URL or in the session.');
        }

        $user = $this->users->findByPasswordResetToken($token);

        if (!$user instanceof User || !$user->isPasswordResetTokenValid($token)) {
            $request->getSession()->remove(self::SESSION_TOKEN_KEY);
            $this->addFlash('error', $this->translator->trans('account.reset_password.flash.invalid_token'));

            return $this->redirectToRoute('app_forgot_password_request');
        }

        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $request->getSession()->remove(self::SESSION_TOKEN_KEY);
            $user->clearPasswordResetToken();
            $user->password = $this->passwordHasher->hashPassword($user, $form->get('plainPassword')->getData());
            $this->em->flush();

            $this->addFlash('success', $this->translator->trans('account.reset_password.flash.success'));

            return $this->redirectToRoute('app_login');
        }

        return $this->renderFormResponse('@Account/reset_password/reset.html.twig', $form);
    }
}
