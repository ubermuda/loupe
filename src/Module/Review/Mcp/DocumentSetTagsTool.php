<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Exception\DomainErrors;
use App\Module\Review\Command\SetDocumentTagsCommand;
use App\Module\Review\Command\SetDocumentTagsHandler;
use App\Module\Review\Entity\Tag;
use App\Module\Review\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Replaces a document's tags wholesale, so a caller that knows the intended set
 * can send it without first reading what is there and diffing.
 */
#[McpTool(name: 'document_set_tags', description: 'Replace a document\'s tags with the given set. Passing an empty list clears them. Tags are lowercased and created on first use — read project_list_tags first so a new name is a deliberate one.')]
final readonly class DocumentSetTagsTool
{
    public function __construct(
        private SetDocumentTagsHandler $setTags,
        private ReviewSubjectResolver $subjects,
        private ToolCallErrorMessages $errorMessages,
    ) {
    }

    /**
     * The `string[]` on $tags is not interchangeable with `list<string>`: the SDK
     * infers a parameter's JSON-schema `items` from the docblock type and parses
     * only the `T[]` and `array<T>` spellings, so `list<string>` publishes an
     * array of anything.
     *
     * @param string   $documentId The UUID of the document to tag
     * @param string[] $tags       The complete set of tag names the document should carry, replacing whatever it has now
     *
     * @return array{documentId: string, tags: list<string>}
     */
    public function __invoke(string $documentId, array $tags): array
    {
        try {
            $document = $this->subjects->requireDocument($documentId, McpBoundProjectVoter::DOCUMENT_WRITE);

            $applied = ($this->setTags)(new SetDocumentTagsCommand($document, $tags));

            return [
                'documentId' => (string) $document->id,
                'tags' => array_map(static fn (Tag $tag): string => $tag->name, $applied),
            ];
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The document tags could not be set. The error has been logged.', previous: $e);
        }
    }
}
