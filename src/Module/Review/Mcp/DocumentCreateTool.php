<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Exception\DomainErrors;
use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Entity\Tag;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Create a Markdown document for human review and return its id and review URL.
 */
#[McpTool(name: 'document_create', description: 'Create a Markdown document for human review. Pass description to say what this first version is, tags to group it with related documents, and references to link the documents this one accompanies, supersedes or answers. Tags are lowercased and created on first use — read tag_list first so a batch reuses the project\'s existing names.')]
final readonly class DocumentCreateTool
{
    use ResolvesBoundProject;

    /**
     * Reject pathologically large documents before they are parsed and stored.
     * One byte below Postgres's to_tsvector cap of 1,048,575, so anything the
     * app accepts can also be indexed — above it a document stored fine and
     * then failed indexing, leaving it permanently unsearchable.
     */
    public const int MAX_MARKDOWN_BYTES = 1_048_575;

    public function __construct(
        private CreateDocumentHandler $createDocument,
        private AuthenticatedProjectResolver $projectResolver,
        private ToolCallErrorMessages $errorMessages,
        private UrlGeneratorInterface $urls,
        private ReviewSubjectResolver $subjects,
    ) {
    }

    /**
     * Neither array parameter may be spelled `list<string>`: the SDK infers a
     * parameter's JSON-schema `items` from the docblock type and parses only the
     * `T[]` and `array<T>` forms, so `list<string>` publishes an array of
     * anything. `string[]` and `array<string>` are both fine.
     *
     * @param string        $title       The document title
     * @param string        $markdown    The document content in Markdown format
     * @param string|null   $description What this first version is, in one or two sentences — the brief it answers or the question it exists to settle
     * @param string[]      $tags        Tag names to group this document by, lowercased on write and created if the project does not have them yet
     * @param array<string> $references  Ids of documents in the same project that this one points at; the link is shown on both documents
     *
     * @return array{documentId: string, reviewUrl: string, tags: list<string>}
     */
    public function __invoke(string $title, string $markdown, ?string $description = null, array $tags = [], array $references = []): array
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

            // Named, not positional: the command now ends in two optional arrays
            // of strings, so a mis-ordered call would be silent.
            $doc = ($this->createDocument)(new CreateDocumentCommand(
                project: $project,
                title: $title,
                markdown: $markdown,
                description: $description,
                tagNames: $tags,
                references: $this->subjects->requireReferences($references),
            ));

            return [
                'documentId' => (string) $doc->id,
                'reviewUrl' => $this->urls->generate('app_document_review', [
                    'projectId' => (string) $doc->project->id,
                    'documentId' => (string) $doc->id,
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                'tags' => array_values(array_map(static fn (Tag $tag): string => $tag->name, $doc->tags->toArray())),
            ];
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The document could not be created. The error has been logged.', previous: $e);
        }
    }
}
