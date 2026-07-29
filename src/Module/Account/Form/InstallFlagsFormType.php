<?php

declare(strict_types=1);

namespace App\Module\Account\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<InstallFlagsRequest> */
final class InstallFlagsFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('registrationCap', IntegerType::class, [
                'label' => 'account.form.install_flags_form.registration_cap.label',
            ])
            ->add('registrationEnabled', CheckboxType::class, [
                'label' => 'account.form.install_flags_form.registration_enabled.label',
                'required' => false,
            ])
            ->add('billingEnabled', CheckboxType::class, [
                'label' => 'account.form.install_flags_form.billing_enabled.label',
                'required' => false,
            ])
            ->add('billingTrialDays', IntegerType::class, [
                'label' => 'account.form.install_flags_form.billing_trial_days.label',
            ])
            ->add('billingStripePriceId', TextType::class, [
                'label' => 'account.form.install_flags_form.billing_stripe_price_id.label',
                'required' => false,
            ])
            ->add('authGithubEnabled', CheckboxType::class, [
                'label' => 'account.form.install_flags_form.auth_github_enabled.label',
                'required' => false,
            ])
            ->add('authGoogleEnabled', CheckboxType::class, [
                'label' => 'account.form.install_flags_form.auth_google_enabled.label',
                'required' => false,
            ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => InstallFlagsRequest::class]);
    }
}
