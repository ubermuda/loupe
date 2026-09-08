<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Context;

use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Turns the opaque marker a widget embed carries into something a reviewer can
 * read.
 *
 * This exists because SiteReview must not know what the marker means. The
 * module that wrote a marker implements this, so the dependency runs towards
 * SiteReview and a module fenced as a leaf can still be named on the page.
 *
 * Returning null is the ordinary answer, and it is load-bearing. A resolver
 * must decline a marker it does not recognise, one naming a row that no longer
 * exists, and one naming a row of another project. The widget then says
 * nothing, which is what tells the reviewer the marker will not be honoured
 * rather than letting them believe it will.
 */
#[AutoconfigureTag('app.site_review_context_label_resolver')]
interface ContextLabelResolverInterface
{
    public function resolve(string $context, Project $project): ?ContextLabel;
}
