<?php

declare(strict_types=1);

namespace App\Module\Insights\Form;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Insights\Entity\AnalysisTopic;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<StartAnalysisRequest> */
final class StartAnalysisFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('topic', EnumType::class, [
            'class' => AnalysisTopic::class,
            // Only these topics have a section in the agent skill so far.
            'choices' => [AnalysisTopic::Cost, AnalysisTopic::Time, AnalysisTopic::Host],
            'choice_label' => static fn (AnalysisTopic $topic): string => 'insights.form.start_analysis_form.topic.'.$topic->value,
            'label' => 'insights.form.start_analysis_form.topic.label',
        ]);
        $builder->add('range', EnumType::class, [
            'class' => MetricRange::class,
            'choice_label' => static fn (MetricRange $range): string => 'insights.form.start_analysis_form.range.'.$range->value,
            'label' => 'insights.form.start_analysis_form.range.label',
        ]);
        $builder->add('model', TextType::class, [
            'required' => false,
            'label' => 'insights.form.start_analysis_form.model.label',
        ]);
        $builder->add('effort', ChoiceType::class, [
            'required' => false,
            'choices' => array_combine(WorkRequest::EFFORTS, WorkRequest::EFFORTS),
            'choice_label' => static fn (string $effort): string => 'insights.form.start_analysis_form.effort.'.$effort,
            'placeholder' => 'insights.form.start_analysis_form.effort.placeholder',
            'label' => 'insights.form.start_analysis_form.effort.label',
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => StartAnalysisRequest::class]);
    }
}
