<?php

declare(strict_types=1);

namespace App\Module\Insights\Form;

use App\Module\Insights\Entity\Proposal;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<DismissProposalRequest> */
final class DismissProposalFormType extends AbstractType
{
    public static function nameFor(Proposal $proposal): string
    {
        return 'dismiss_proposal_'.$proposal->id;
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('reason', TextType::class, [
            'required' => false,
            'label' => 'insights.form.dismiss_proposal_form.reason.label',
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DismissProposalRequest::class]);
    }
}
