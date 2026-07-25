<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
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

    /**
     * Retrieves a FormView forwarded as a request attribute by forward()
     * (e.g. a sibling controller re-rendering this action's view after a
     * failed submission). Returns null when nothing was forwarded, so
     * callers fall back to a fresh form: `$this->getInjectedFormView($request, 'key') ?? $this->createForm(...)->createView()`.
     */
    protected function getInjectedFormView(Request $request, string $key): ?FormView
    {
        $view = $request->attributes->get($key);

        return $view instanceof FormView ? $view : null;
    }
}
