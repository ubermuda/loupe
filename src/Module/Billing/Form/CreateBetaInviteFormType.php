<?php

declare(strict_types=1);

namespace App\Module\Billing\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<CreateBetaInviteRequest>
 */
final class CreateBetaInviteFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('note', TextType::class, [
            'required' => false,
            'label' => 'billing.form.create_beta_invite_form.note.label',
            'help' => 'billing.form.create_beta_invite_form.note.help',
            'attr' => ['maxlength' => 255, 'placeholder' => 'billing.form.create_beta_invite_form.note.placeholder'],
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CreateBetaInviteRequest::class,
        ]);
    }
}
