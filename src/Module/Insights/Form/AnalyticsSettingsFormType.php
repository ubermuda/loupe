<?php

declare(strict_types=1);

namespace App\Module\Insights\Form;

use App\Module\Bridge\Entity\WorkRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<AnalyticsSettingsRequest> */
final class AnalyticsSettingsFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('model', TextType::class, [
            'required' => false,
            'label' => 'insights.form.analytics_settings_form.model.label',
        ]);
        $builder->add('effort', ChoiceType::class, [
            'required' => false,
            'choices' => array_combine(WorkRequest::EFFORTS, WorkRequest::EFFORTS),
            'choice_label' => static fn (string $effort): string => 'insights.form.analytics_settings_form.effort.'.$effort,
            'placeholder' => 'insights.form.analytics_settings_form.effort.placeholder',
            'label' => 'insights.form.analytics_settings_form.effort.label',
        ]);
        $builder->add('collectFullText', CheckboxType::class, [
            'required' => false,
            'label' => 'insights.form.analytics_settings_form.collect_full_text.label',
            'help' => 'insights.form.analytics_settings_form.collect_full_text.help',
        ]);
        $builder->add('subcommandPrograms', TextType::class, [
            'required' => false,
            'label' => 'insights.form.analytics_settings_form.subcommand_programs.label',
            'help' => 'insights.form.analytics_settings_form.subcommand_programs.help',
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AnalyticsSettingsRequest::class]);
    }
}
