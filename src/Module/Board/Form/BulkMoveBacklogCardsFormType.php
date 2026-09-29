<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\BoardColumn;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The bulk bar of the Backlog page. The row checkboxes join it through their
 * form attribute, and each button of the bar carries the column id.
 *
 * @extends AbstractType<BulkMoveBacklogCardsRequest>
 */
final class BulkMoveBacklogCardsFormType extends AbstractType
{
    /** The page writes the form by hand, and the controller builds it under this name. */
    public const string NAME = 'backlog_bulk_move';

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('ids', CollectionType::class, [
                'entry_type' => TextType::class,
                'allow_add' => true,
                'label' => false,
            ])
            ->add('column', BoardColumnChoiceType::class, [
                'label' => false,
                'project' => $options['backlog']->project,
                'exclude' => $options['backlog'],
            ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => BulkMoveBacklogCardsRequest::class]);
        $resolver->setRequired('backlog');
        $resolver->setAllowedTypes('backlog', BoardColumn::class);
    }
}
