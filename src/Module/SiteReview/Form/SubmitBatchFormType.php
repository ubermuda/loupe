<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A batch of site-review comments. Bound from a JSON request body via
 * `$form->submit($decodedArray)`, not a rendered HTML form.
 *
 * @extends AbstractType<SubmitBatchRequest>
 */
class SubmitBatchFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('comments', CollectionType::class, [
            'entry_type' => SiteReviewCommentFormType::class,
            'allow_add' => true,
            'allow_delete' => true,
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SubmitBatchRequest::class,
            'csrf_protection' => false,
            'allow_extra_fields' => true,
        ]);
    }
}
