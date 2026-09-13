<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A select of one board's columns, in board order. A column from another
 * board is not a choice, so the form refuses it.
 *
 * @extends AbstractType<BoardColumn>
 */
final class BoardColumnChoiceType extends AbstractType
{
    #[\Override]
    public function getParent(): string
    {
        return EntityType::class;
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('project');
        $resolver->setAllowedTypes('project', Project::class);
        $resolver->setDefaults([
            'class' => BoardColumn::class,
            'query_builder' => static fn (Options $options): \Closure => static fn (BoardColumnRepository $columns): QueryBuilder => $columns->createQueryBuilder('k')
                ->andWhere('k.project = :project')
                ->setParameter('project', $options['project'])
                ->orderBy('k.position', 'ASC'),
            'choice_label' => static fn (BoardColumn $column): string => $column->label,
            // A seeded label is a translation key, and EntityType translates no
            // choice unless a domain is named.
            'choice_translation_domain' => 'messages',
        ]);
    }
}
