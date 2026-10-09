<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Project\Entity\Project;
use App\Module\SiteReview\ParentType\ParentType;
use App\Module\SiteReview\ParentType\ParentTypeProviderInterface;
use App\Module\Workflow\Contract\CardTypeCatalog;
use App\Module\Workflow\Contract\CardTypeDefinition;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsAlias(ParentTypeProviderInterface::class)]
final readonly class BoardParentTypeProvider implements ParentTypeProviderInterface
{
    public function __construct(
        private CardTypeCatalog $catalog,
        private TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function forProject(Project $project): array
    {
        return array_values(array_map(
            fn (CardTypeDefinition $type): ParentType => new ParentType($type->key, $this->translator->trans($type->label)),
            array_filter(
                $this->catalog->forProject($project->requireId())->all,
                static fn (CardTypeDefinition $type): bool => $type->children,
            ),
        ));
    }
}
