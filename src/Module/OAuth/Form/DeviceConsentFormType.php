<?php

declare(strict_types=1);

namespace App\Module\OAuth\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The code travels in the page URL, not in the form, so the handler reads it
 * again and a stale page cannot answer a different code.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class DeviceConsentFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('approve', SubmitType::class, ['label' => 'oauth.form.device_consent_form.approve.label'])
            ->add('deny', SubmitType::class, ['label' => 'oauth.form.device_consent_form.deny.label']);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        // A session-stored token, not the stateless 'submit' one: that one needs
        // csrf_protection_controller.js, and a click before it loads got a 422.
        $resolver->setDefaults(['csrf_token_id' => 'oauth-device-consent']);
    }
}
