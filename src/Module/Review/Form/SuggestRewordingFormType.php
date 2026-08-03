<?php

declare(strict_types=1);

namespace App\Module\Review\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The rewording composer. `quote`/`prefix`/`suffix` are machine-captured by the
 * comment-anchor Stimulus controller; `replacement` is pre-filled with the
 * selected text so the reviewer edits in place, and `body` is an optional
 * rationale.
 *
 * @extends AbstractType<SuggestRewordingRequest>
 */
class SuggestRewordingFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // trim => false on the captured context for the same reason as the comment
        // composer: a trimmed prefix no longer fingerprints the selection.
        $builder->add('quote', HiddenType::class, [
            'trim' => false,
            'attr' => ['data-comment-anchor-target' => 'suggestQuote'],
        ]);
        $builder->add('prefix', HiddenType::class, [
            'required' => false,
            'trim' => false,
            'attr' => ['data-comment-anchor-target' => 'suggestPrefix'],
        ]);
        $builder->add('suffix', HiddenType::class, [
            'required' => false,
            'trim' => false,
            'attr' => ['data-comment-anchor-target' => 'suggestSuffix'],
        ]);
        // trim => false here too: the replacement is spliced into the document in
        // place of the quote, so leading or trailing space the reviewer typed is
        // part of the edit, not stray whitespace.
        $builder->add('replacement', TextareaType::class, [
            'label' => false,
            'trim' => false,
            'attr' => [
                'data-comment-anchor-target' => 'suggestReplacement',
                'placeholder' => 'review.document.suggestion.replacement_placeholder',
                'rows' => 3,
            ],
        ]);
        $builder->add('body', TextareaType::class, [
            'label' => false,
            'required' => false,
            'attr' => [
                'data-comment-anchor-target' => 'suggestBody',
                'placeholder' => 'review.document.suggestion.rationale_placeholder',
                'rows' => 2,
            ],
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SuggestRewordingRequest::class]);
    }
}
