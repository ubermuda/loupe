<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use Symfony\Component\Uid\Uuid;

interface TemplateSource
{
    /** @throws TemplateMissing when the project is bound to no template */
    public function forProject(Uuid $projectId): Template;
}
