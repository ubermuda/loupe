<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Create a Markdown document for human review and return its id and review URL.
 */
#[McpTool(name: 'document_create', description: 'Create a Markdown document for human review.')]
final readonly class CreateDocumentTool
{
    use ResolvesBoundProject;

    /** Reject pathologically large documents before they are parsed and stored. */
    public const int MAX_MARKDOWN_BYTES = 1_048_576;

    public function __construct(
        private CreateDocumentHandler $createDocument,
        private AuthenticatedProjectResolver $projectResolver,
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
        $project = $this->requireBoundProject($this->projectResolver);

        if (\strlen($markdown) > self::MAX_MARKDOWN_BYTES) {
            throw new ToolCallException('The markdown content exceeds the maximum allowed size.');
        }

        try {
            $doc = ($this->createDocument)(new CreateDocumentCommand($project, $title, $markdown));
        } catch (\Throwable $e) {
            throw new ToolCallException('The document could not be created. The error has been logged.', previous: $e);
        }

        return [
            'documentId' => (string) $doc->id,
            'reviewUrl' => $this->urls->generate('app_document_review', [
                'projectId' => (string) $doc->project->id,
                'documentId' => (string) $doc->id,
            ], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }
}
