<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Context;

use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Asks each resolver in turn and takes the first answer. */
final readonly class ContextLabelResolver
{
    /** @param iterable<ContextLabelResolverInterface> $resolvers */
    public function __construct(
        #[AutowireIterator('app.site_review_context_label_resolver')]
        private iterable $resolvers,
    ) {
    }

    public function resolve(?string $context, Project $project): ?ContextLabel
    {
        $context = trim($context ?? '');
        if ('' === $context) {
            return null;
        }

        foreach ($this->resolvers as $resolver) {
            $label = $resolver->resolve($context, $project);
            if (null !== $label) {
                return $label;
            }
        }

        return null;
    }
}
