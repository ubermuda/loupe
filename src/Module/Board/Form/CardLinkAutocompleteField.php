<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Security\ProjectVoter;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

/**
 * A search-as-you-type pick of another card of the project. Pass
 * `extra_options` with `projectId` and, on an edit, `excludeCardId`.
 *
 * The endpoint rebuilds this field from the signed `extra_options` alone, so
 * the scope and the access check both read them and never a form option.
 *
 * @extends AbstractType<Card>
 */
#[AsEntityAutocompleteField(alias: 'board_card_link')]
final class CardLinkAutocompleteField extends AbstractType
{
    private const int MAX_RESULTS = 20;

    public function __construct(
        private readonly BoardAvailability $board,
        private readonly ProjectRepository $projects,
    ) {
    }

    #[\Override]
    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Card::class,
            'choice_label' => static fn (Card $card): string => '#'.$card->number.' '.$card->title,
            'choice_translation_domain' => false,
            'max_results' => self::MAX_RESULTS,
            // Also the choice list a submitted id must be in, so a card of
            // another project fails validation. A limit here would refuse
            // older cards, so filter_query sets the page size instead.
            'query_builder' => static fn (Options $options): \Closure => static fn (CardRepository $cards): QueryBuilder => $cards->linkCandidates(
                self::uuidOption($options, 'projectId'),
                self::uuidOption($options, 'excludeCardId'),
            ),
            'filter_query' => static function (QueryBuilder $qb, string $query, CardRepository $cards): void {
                // The bundle skips max_results when filter_query is set.
                $cards->matchLinkQuery($qb->setMaxResults(self::MAX_RESULTS), $query);
            },
            'security' => fn (Options $options): \Closure => function (Security $security) use ($options): bool {
                $this->board->requireEnabled();
                $projectId = self::uuidOption($options, 'projectId');
                $project = null === $projectId ? null : $this->projects->find($projectId);

                return null !== $project && $security->isGranted(ProjectVoter::MANAGE, $project);
            },
        ]);
    }

    /** @param Options<array<string, mixed>> $options */
    private static function uuidOption(Options $options, string $key): ?Uuid
    {
        $extraOptions = $options['extra_options'];
        $value = \is_array($extraOptions) ? ($extraOptions[$key] ?? null) : null;

        return \is_string($value) && Uuid::isValid($value) ? Uuid::fromString($value) : null;
    }
}
