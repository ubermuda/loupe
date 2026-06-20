<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A single comment in a site-review batch. Bound from JSON, not a rendered form.
 *
 * @extends AbstractType<SiteReviewCommentRequest>
 */
class SiteReviewCommentFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('body', TextType::class, ['required' => false]);
        $builder->add('selector', TextType::class, ['required' => false]);
        $builder->add('text', TextType::class, ['required' => false]);
        $builder->add('url', TextType::class, ['required' => false]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SiteReviewCommentRequest::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }
}
