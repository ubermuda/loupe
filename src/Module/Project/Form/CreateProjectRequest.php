<?php

declare(strict_types=1);

namespace App\Module\Project\Form;

use App\Doctrine\SearchLanguage;
use Symfony\Component\Validator\Constraints as Assert;

class CreateProjectRequest
{
    public const string WITH_WORKFLOW_TEMPLATE = 'with_workflow_template';

    public function __construct(
        #[Assert\Length(max: 100, normalizer: 'trim')]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $name = null,

        #[Assert\Length(max: 255, normalizer: 'trim')]
        public ?string $domain = null,

        /**
         * Nullable so a submit that omits the select fails validation rather
         * than throwing out of the property mapper.
         */
        #[Assert\NotNull]
        public ?SearchLanguage $searchLanguage = SearchLanguage::DEFAULT,

        #[Assert\Length(max: 500, normalizer: 'trim')]
        public ?string $description = null,

        /** Validated only where the form has the field, because the edit form leaves it out. */
        #[Assert\NotNull(groups: [self::WITH_WORKFLOW_TEMPLATE])]
        public ?string $workflowTemplate = null,
    ) {
    }
}
