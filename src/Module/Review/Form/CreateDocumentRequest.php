<?php

declare(strict_types=1);

namespace App\Module\Review\Form;

use App\Module\Review\Entity\Document;
use Symfony\Component\Validator\Constraints as Assert;

final class CreateDocumentRequest
{
    /** @param list<string>|null $workLinkIds */
    public function __construct(
        #[Assert\Length(max: Document::MAX_TITLE_LENGTH)]
        #[Assert\NotBlank]
        public ?string $title = null,

        #[Assert\NotBlank]
        public ?string $markdown = null,
        public ?array $workLinkIds = null,
    ) {
    }
}
