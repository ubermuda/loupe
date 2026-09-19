<?php

declare(strict_types=1);

namespace App\Module\OAuth\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;

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
}
