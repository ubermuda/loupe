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
#[McpTool(name: 'document_create', description: 'Create a Markdown document for human review. Pass description to say what this first version is, and references to link the documents this one accompanies, supersedes or answers.')]
final readonly class DocumentCreateTool
{
    use ResolvesBoundProject;

    /** Reject pathologically large documents before they are parsed and stored. */
    public const int MAX_MARKDOWN_BYTES = 1_048_576;

    public function __construct(
        private CreateDocumentHandler $createDocument,
        private AuthenticatedProjectResolver $projectResolver,
        private UrlGeneratorInterface $urls,
        private ReviewSubjectResolver $subjects,
    ) {
    }

    /**
     * @param string        $title       The document title
     * @param string        $markdown    The document content in Markdown format
     * @param string|null   $description What this first version is, in one or two sentences — the brief it answers or the question it exists to settle
     * @param array<string> $references  Ids of documents in the same project that this one points at; the link is shown on both documents
     *
     * @return array{documentId: string, reviewUrl: string}
     */
    public function __invoke(string $title, string $markdown, ?string $description = null, array $references = []): array
    {
        try {
            $project = $this->requireBoundProject($this->projectResolver);

            if (\strlen($markdown) > self::MAX_MARKDOWN_BYTES) {
                throw new ToolCallException('The markdown content exceeds the maximum allowed size.');
            }

            // A whitespace-only description means "none given", but "0" is a
            // description a caller actually sent — `?:` would discard it.
            $description = null === $description ? null : trim($description);
            if ('' === $description) {
                $description = null;
            }

            $doc = ($this->createDocument)(new CreateDocumentCommand(
                project: $project,
                title: $title,
                markdown: $markdown,
                description: $description,
                references: $this->subjects->requireReferences($references),
            ));

            return [
                'documentId' => (string) $doc->id,
                'reviewUrl' => $this->urls->generate('app_document_review', [
                    'projectId' => (string) $doc->project->id,
                    'documentId' => (string) $doc->id,
                ], UrlGeneratorInterface::ABSOLUTE_URL),
            ];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The document could not be created. The error has been logged.', previous: $e);
        }
    }
}
