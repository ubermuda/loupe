<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;

abstract class AppController extends AbstractController
{
    /**
     * Renders a form response, setting 422 status when the form was submitted (invalid).
     *
     * @param FormInterface<mixed> $form
     * @param array<string, mixed> $extra
     */
    protected function renderFormResponse(string $view, FormInterface $form, array $extra = []): Response
    {
        return $this->render($view, array_merge(['form' => $form], $extra))
            ->setStatusCode($form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }
}
