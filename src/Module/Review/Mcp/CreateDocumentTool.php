<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use Mcp\Capability\Attribute\McpTool;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Create a Markdown document for human review and return its id and review URL.
 */
#[McpTool(name: 'create_document', description: 'Create a Markdown document for human review.')]
final readonly class CreateDocumentTool
{
    public function __construct(
        private CreateDocumentHandler $createDocument,
        private Security $security,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param string $title    The document title
     * @param string $markdown The document content in Markdown format
     *
     * @return array{documentId: string, reviewUrl: string}
     */
    public function __invoke(string $title, string $markdown): array
    {
        // The ^/mcp firewall requires ROLE_USER, so an authenticated User is guaranteed here.
        /** @var User $user */
        $user = $this->security->getUser();
        $doc = ($this->createDocument)(new CreateDocumentCommand($user, $title, $markdown));

        return [
            'documentId' => (string) $doc->id,
            // Route app_document_review is provided by the reviewer UI (Task 11); the tool
            // resolves it to an absolute URL the agent shares with the human reviewer.
            'reviewUrl' => $this->urls->generate('app_document_review', ['id' => $doc->id], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }
}
