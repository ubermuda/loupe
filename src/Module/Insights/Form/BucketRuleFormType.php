<?php

declare(strict_types=1);

namespace App\Module\Insights\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<BucketRuleRequest> */
final class BucketRuleFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('pattern', TextType::class, [
            'required' => false,
            'label' => 'insights.form.bucket_rule_form.pattern.label',
            'help' => 'insights.form.bucket_rule_form.pattern.help',
        ]);
        $builder->add('bucket', TextType::class, [
            'required' => false,
            'label' => 'insights.form.bucket_rule_form.bucket.label',
            'help' => 'insights.form.bucket_rule_form.bucket.help',
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => BucketRuleRequest::class]);
    }
}
