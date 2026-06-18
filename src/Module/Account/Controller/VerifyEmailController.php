<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/register/verify', name: 'app_verify_email')]
class VerifyEmailController extends AppController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $token = $request->query->get('token');

        if (!is_string($token) || '' === $token) {
            $this->addFlash('error', $this->translator->trans('account.registration.flash.verification_invalid'));

            return $this->redirectToRoute('app_register_check_email');
        }

        $user = $this->users->findByEmailVerificationToken($token);
        if (!$user instanceof User) {
            $this->addFlash('error', $this->translator->trans('account.registration.flash.verification_invalid'));

            return $this->redirectToRoute('app_register_check_email');
        }

        if (!$user->isEmailVerificationTokenValid($token)) {
            $this->addFlash('error', $this->translator->trans('account.registration.flash.verification_invalid'));

            return $this->redirectToRoute('app_register_check_email');
        }

        $user->clearEmailVerificationToken();
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $this->em->flush();

        return $this->security->login($user, 'form_login', 'main')
            ?? $this->redirectToRoute('app_home');
    }
}
