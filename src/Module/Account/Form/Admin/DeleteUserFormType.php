<?php

declare(strict_types=1);

namespace App\Module\Account\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Binds the deletion confirmation. The field is a plain
 * `<input name="delete_user_form[confirmEmail]">` in the detail template — it
 * carries its own label there — and CSRF is enforced by the
 * `#[CsrfToken('admin-user-delete')]` attribute on the controller.
 *
 * @extends AbstractType<DeleteUserRequest>
 */
final class DeleteUserFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('confirmEmail', TextType::class, ['label' => false]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DeleteUserRequest::class,
            'csrf_protection' => false,
        ]);
    }
}
