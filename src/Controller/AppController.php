<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\DomainErrors;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

abstract class AppController extends AbstractController
{
    private TranslatorInterface $translator;

    /**
     * Setter injection (mirrors AbstractController::setContainer()) so every
     * subclass gets the translator without having to add it to its own
     * constructor.
     */
    #[Required]
    public function setTranslator(TranslatorInterface $translator): void
    {
        $this->translator = $translator;
    }

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
     * Maps each field-level domain failure onto the form as a translated
     * FormError. Standard body for `catch (DomainErrors $e) { ... }` blocks
     * after a command handler call.
     *
     * @param FormInterface<mixed> $form
     */
    protected function applyDomainErrors(FormInterface $form, DomainErrors $e): void
    {
        foreach ($e->errors as $field => $translationKey) {
            $form->get($field)->addError(new FormError($this->translator->trans($translationKey)));
        }
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
