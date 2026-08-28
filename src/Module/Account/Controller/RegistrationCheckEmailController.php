<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/register/check-email', name: 'app_register_check_email')]
class RegistrationCheckEmailController extends AppController
{
    public function __invoke(Request $request): Response
    {
        $email = $request->getSession()->get('registration_email');
        $user = $this->getUser();
        $session = $request->getSession();
        $hasFlash = $session instanceof FlashBagAwareSessionInterface
            && !empty($session->getFlashBag()->peekAll());

        if (null === $email && !$user instanceof User && !$hasFlash) {
            return $this->redirectToRoute('app_register');
        }

        return $this->render('@Account/registration/check_email.html.twig', [
            'email' => $email ?? ($user instanceof User ? $user->email : null),
        ]);
    }
}
