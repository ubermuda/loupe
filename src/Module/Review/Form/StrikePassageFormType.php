<?php

declare(strict_types=1);

namespace App\Module\Review\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The strike form: every field is hidden and machine-filled, so the form never
 * appears on screen and the Stimulus controller submits it outright.
 *
 * @extends AbstractType<StrikePassageRequest>
 */
class StrikePassageFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // trim => false, as on the comment composer: AnchorService matches the last
        // 8 characters of the prefix against the document, so trimming the boundary
        // space makes the fingerprint unmatchable for any selection starting or
        // ending at a word boundary.
        $builder->add('quote', HiddenType::class, [
            'trim' => false,
            'attr' => ['data-comment-anchor-target' => 'strikeQuote'],
        ]);
        $builder->add('prefix', HiddenType::class, [
            'required' => false,
            'trim' => false,
            'attr' => ['data-comment-anchor-target' => 'strikePrefix'],
        ]);
        $builder->add('suffix', HiddenType::class, [
            'required' => false,
            'trim' => false,
            'attr' => ['data-comment-anchor-target' => 'strikeSuffix'],
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => StrikePassageRequest::class]);
    }
}
