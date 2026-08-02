<?php

declare(strict_types=1);

namespace App\Module\Review\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The new-comment composer. `quote`/`prefix`/`suffix` are the verbatim selected
 * text and its surrounding context, captured and filled client-side by the
 * comment-anchor Stimulus controller (empty for an untargeted comment); only
 * `body` is user-entered.
 *
 * @extends AbstractType<AddCommentRequest>
 */
class AddCommentFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // trim => false on all three: the Form component trims by default, which for
        // machine-captured context is data corruption, not sanitisation. AnchorService
        // matches the last 8 characters of the prefix against the document, so losing
        // the boundary space makes the fingerprint unmatchable for every selection that
        // begins or ends at a word boundary — and the quote itself may legitimately
        // start or end with whitespace the reviewer selected.
        $builder->add('quote', HiddenType::class, [
            'required' => false,
            'trim' => false,
            'attr' => ['data-comment-anchor-target' => 'quote'],
        ]);
        $builder->add('prefix', HiddenType::class, [
            'required' => false,
            'trim' => false,
            'attr' => ['data-comment-anchor-target' => 'prefix'],
        ]);
        $builder->add('suffix', HiddenType::class, [
            'required' => false,
            'trim' => false,
            'attr' => ['data-comment-anchor-target' => 'suffix'],
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
