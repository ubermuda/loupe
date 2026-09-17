<?php

declare(strict_types=1);

namespace App\Module\Review\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<SubmitReviewRequest>
 */
final class SubmitReviewFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('verdict', ChoiceType::class, [
            'expanded' => true,
            'placeholder' => false,
            'label' => 'review.form.submit_review_form.verdict.label',
            'choices' => [
                'review.form.submit_review_form.verdict.approve' => 'approved',
                'review.form.submit_review_form.verdict.changes' => 'changes-requested',
            ],
        ]);
        $builder->add('versionNumber', IntegerType::class, ['required' => false, 'label' => false]);
        $builder->add('expectedReviewId', HiddenType::class, ['required' => false]);
        $builder->add('note', TextareaType::class, [
            'required' => false,
            'label' => 'review.form.submit_review_form.note.label',
            'help' => 'review.form.submit_review_form.note.help',
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SubmitReviewRequest::class,
        ]);
    }
}
