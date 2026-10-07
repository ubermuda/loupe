<?php

declare(strict_types=1);

namespace App\Module\Project\Form;

use App\Module\Project\Entity\Project;
use App\Module\Project\Service\SiteOrigins;
use Symfony\Component\Validator\Constraints as Assert;

class UpdateProjectAllowedOriginsRequest
{
    public function __construct(
        #[Assert\Length(max: SiteOrigins::MAX_TEXT_LENGTH)]
        public ?string $origins = null,
    ) {
    }

    public static function fromProject(Project $project): self
    {
        return new self(implode("\n", $project->allowedOrigins));
    }
}
