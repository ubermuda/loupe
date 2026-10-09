<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Service\NewTextAnchorsBuilder;
use App\Module\Review\ValueObject\NewTextAnchors;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The passages one version added since the version before it. */
final readonly class ShowNewTextAnchorsHandler
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
        private NewTextAnchorsBuilder $newTextAnchors,
    ) {
    }

    public function __invoke(ShowNewTextAnchorsCommand $command): NewTextAnchors
    {
        $version = $this->documentVersions->findByNumber($command->document, $command->versionNumber)
            ?? throw new NotFoundHttpException(\sprintf('Document has no version %d.', $command->versionNumber));

        return $this->newTextAnchors->build($version);
    }
}
