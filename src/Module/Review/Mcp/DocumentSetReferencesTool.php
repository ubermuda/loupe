<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Exception\DomainErrors;
use App\Module\Review\Command\SetDocumentReferencesCommand;
use App\Module\Review\Command\SetDocumentReferencesHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Links a document to others without submitting content, so documents that
 * already exist can be connected without the cost of a revision — which would
 * mint a version, re-anchor open comments and drop every highlight.
 */
#[McpTool(name: 'document_set_references', description: 'Replace the set of documents a document points at, without creating a new version. Passing an empty list clears them. Targets must be in the same project and a document cannot reference itself; read document_get first, because this replaces the current set rather than adding to it.')]
final readonly class DocumentSetReferencesTool
{
    public function __construct(
        private SetDocumentReferencesHandler $setReferences,
        private ReviewSubjectResolver $subjects,
        private ToolCallErrorMessages $errorMessages,
    ) {
    }

    /**
     * The `array<string>` on $references is not interchangeable with
     * `list<string>`: the SDK infers a parameter's JSON-schema `items` from the
     * docblock type and parses only the `T[]` and `array<T>` spellings, so
     * `list<string>` publishes an array of anything.
     *
     * @param string        $documentId The UUID of the document whose references to replace
     * @param array<string> $references The complete set of document ids this one should point at, replacing whatever it points at now; pass an empty list to clear them
     *
     * @return array{documentId: string, references: list<string>}
     */
    public function __invoke(string $documentId, array $references): array
    {
        try {
            // Writing the link is a write to the source only — pointing at a
            // document changes nothing about the target, so its ids are
            // resolved with a read grant.
            $document = $this->subjects->requireDocument($documentId, McpBoundProjectVoter::DOCUMENT_WRITE);

            $applied = ($this->setReferences)(new SetDocumentReferencesCommand(
                $document,
                $this->subjects->requireReferences($references),
            ));

            return [
                'documentId' => (string) $document->id,
                'references' => array_map(static fn (Document $reference): string => (string) $reference->id, $applied),
            ];
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The document references could not be set. The error has been logged.', previous: $e);
        }
    }
}
