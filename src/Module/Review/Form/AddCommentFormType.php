<?php

declare(strict_types=1);

namespace App\Module\Review\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The new-comment composer. `start`/`length` are character offsets computed and
 * filled client-side by the comment-anchor Stimulus controller; only `body` is
 * user-entered.
 *
 * @extends AbstractType<AddCommentRequest>
 */
class AddCommentFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('start', IntegerType::class, [
            'attr' => ['hidden' => true, 'data-comment-anchor-target' => 'start'],
        ]);
        $builder->add('length', IntegerType::class, [
            'attr' => ['hidden' => true, 'data-comment-anchor-target' => 'length'],
        ]);
        $builder->add('body', TextareaType::class, [
            'label' => false,
            'attr' => [
                'data-comment-anchor-target' => 'composerBody',
                'placeholder' => 'review.document.comment.placeholder',
                'rows' => 3,
            ],
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AddCommentRequest::class]);
    }
}
