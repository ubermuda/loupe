<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use Symfony\Component\Uid\Uuid;

/** The project is bound to no workflow template. */
final class TemplateMissing extends \RuntimeException
{
    public function __construct(
        public readonly Uuid $projectId,
    ) {
        parent::__construct(\sprintf('Project %s is bound to no workflow template.', $projectId->toRfc4122()));
    }
}
