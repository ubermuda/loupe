<?php

declare(strict_types=1);

namespace App\Module\Readiness\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<UpdateReadinessSettingsRequest>
 */
final class UpdateReadinessSettingsFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('showGuide', CheckboxType::class, [
            'required' => false,
            'label' => 'readiness.form.update_readiness_settings_form.show_guide.label',
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => UpdateReadinessSettingsRequest::class]);
    }
}
