<?php

declare(strict_types=1);

namespace App\Module\Review\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Binds the review verdict bar. The verdict-bar template supplies the
 * `verdict` value via two `<button type="submit" name="submit_review_form[verdict]"
 * value="...">` buttons rather than a rendered widget, so this field is
 * never rendered with `form_widget()`. CSRF is already enforced by the
 * `#[CsrfToken('submit-review')]` stateless-token attribute on the
 * controller, so the form's own CSRF extension is disabled here to avoid a
 * redundant second token.
 *
 * @extends AbstractType<SubmitReviewRequest>
 */
final class SubmitReviewFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('verdict', HiddenType::class, [
            'required' => false,
            'label' => false,
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SubmitReviewRequest::class,
            'csrf_protection' => false,
        ]);
    }
}
