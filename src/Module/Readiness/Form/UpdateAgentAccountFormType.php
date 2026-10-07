<?php

declare(strict_types=1);

namespace App\Module\Readiness\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<UpdateAgentAccountRequest>
 */
final class UpdateAgentAccountFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('login', TextType::class, [
            'required' => false,
            'label' => 'readiness.form.update_agent_account_form.login.label',
            'help' => 'readiness.form.update_agent_account_form.login.help',
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => UpdateAgentAccountRequest::class]);
    }
}
