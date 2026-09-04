<?php

declare(strict_types=1);

namespace App\Module\Review\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Binds one section's approve or withdraw button. The sections panel supplies
 * every value as a hand-written hidden input plus a
 * `<button type="submit" name="set_section_approval_form[action]" value="...">`,
 * the same shape as the verdict bar, so no field is rendered with
 * `form_widget()`. CSRF is enforced by `#[CsrfToken('section-approval')]` on the
 * controller, so the form's own extension is off to avoid a second token.
 *
 * @extends AbstractType<SetSectionApprovalRequest>
 */
final class SetSectionApprovalFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('headingId', HiddenType::class, ['required' => false, 'label' => false]);
        $builder->add('action', HiddenType::class, ['required' => false, 'label' => false]);
        // IntegerType rather than HiddenType: the DTO property is an int, and
        // HiddenType applies no transformer, so a submitted "2" would fail to map.
        $builder->add('versionNumber', IntegerType::class, ['required' => false, 'label' => false]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SetSectionApprovalRequest::class,
            'csrf_protection' => false,
        ]);
    }
}
