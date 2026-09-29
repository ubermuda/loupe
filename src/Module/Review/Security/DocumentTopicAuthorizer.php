<?php

declare(strict_types=1);

namespace App\Module\Review\Security;

use App\Mercure\MercureTopicAuthorizerInterface;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Review\Repository\DocumentRepository;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/** Whoever may view a document may listen for changes to its review page. */
final readonly class DocumentTopicAuthorizer implements MercureTopicAuthorizerInterface
{
    public function __construct(
        private ProjectTopicBuilder $topics,
        private DocumentRepository $documents,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    #[\Override]
    public function mayCurrentUserSubscribe(string $topic): ?bool
    {
        $ids = $this->topics->idsFromDocumentTopic($topic);
        if (null === $ids) {
            return null;
        }

        $document = $this->documents->findOneByIdAndProjectId((string) $ids['documentId'], (string) $ids['projectId']);

        return null !== $document && $this->authorization->isGranted(DocumentVoter::VIEW, $document);
    }
}
