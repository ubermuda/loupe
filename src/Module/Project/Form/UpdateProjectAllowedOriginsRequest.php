<?php

declare(strict_types=1);

namespace App\Module\Project\Form;

use App\Module\Project\Entity\Project;
use Symfony\Component\Validator\Constraints as Assert;

class UpdateProjectAllowedOriginsRequest
{
    public function __construct(
        #[Assert\Length(max: 4000)]
        public ?string $origins = null,
    ) {
    }

    public static function fromProject(Project $project): self
    {
        return new self(implode("\n", $project->allowedOrigins));
    }
}
