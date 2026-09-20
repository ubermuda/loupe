<?php

declare(strict_types=1);

namespace App\Module\OAuth\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<DeviceCodeEntryRequest> */
class DeviceCodeEntryFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('userCode', TextType::class, [
                'label' => 'oauth.form.device_code_entry_form.user_code.label',
                'attr' => ['autocomplete' => 'off', 'autocapitalize' => 'characters', 'spellcheck' => 'false'],
            ])
            ->add('submit', SubmitType::class, ['label' => 'oauth.form.device_code_entry_form.submit.label']);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        // A session-stored token, not the stateless 'submit' one: that one needs
        // csrf_protection_controller.js, and a click before it loads got a 422.
        $resolver->setDefaults(['data_class' => DeviceCodeEntryRequest::class, 'csrf_token_id' => 'oauth-device-code-entry']);
    }
}
