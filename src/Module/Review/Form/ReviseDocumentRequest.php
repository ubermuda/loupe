<?php

declare(strict_types=1);

namespace App\Module\Review\Form;

use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use Symfony\Component\Validator\Constraints as Assert;

final class ReviseDocumentRequest
{
    public function __construct(
        #[Assert\Length(max: Document::MAX_TITLE_LENGTH)]
        #[Assert\NotBlank]
        public ?string $title = null,

        #[Assert\NotBlank]
        public ?string $markdown = null,

        #[Assert\NotBlank]
        public ?string $description = null,

        #[Assert\NotNull]
        #[Assert\Positive]
        public ?int $versionNumber = null,
    ) {
    }

    public static function fromVersion(DocumentVersion $version): self
    {
        return new self(title: $version->document->title, markdown: $version->markdownSource, versionNumber: $version->versionNumber);
    }
}
