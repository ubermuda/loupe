<?php

declare(strict_types=1);

namespace App\Module\Account\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Binds the danger zone's suspension reason. The field is a plain
 * `<textarea name="suspend_user_form[reason]">` in the detail template rather
 * than a rendered widget, and CSRF is enforced by the
 * `#[CsrfToken('admin-user-suspend')]` attribute on the controller, so the
 * form's own CSRF extension is disabled here.
 *
 * @extends AbstractType<SuspendUserRequest>
 */
final class SuspendUserFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('reason', TextareaType::class, [
            'required' => false,
            'label' => 'account.form.suspend_user_form.reason.label',
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SuspendUserRequest::class,
            'csrf_protection' => false,
        ]);
    }
}
