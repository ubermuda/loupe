<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<CreateSiteRequest> */
class CreateSiteFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'site_review.form.create_site_form.name.label',
            'attr' => ['placeholder' => 'site_review.form.create_site_form.name.placeholder'],
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CreateSiteRequest::class]);
    }
}
