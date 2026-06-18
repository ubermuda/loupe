<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/forgot-password/check-email', name: 'app_forgot_password_check_email')]
class PasswordResetCheckEmailController extends AppController
{
    public function __invoke(): Response
    {
        return $this->render('@Account/reset_password/check_email.html.twig');
    }
}
