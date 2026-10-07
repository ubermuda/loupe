<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Exception\DomainErrors;
use App\Module\Review\Command\PublishDocumentCommand;
use App\Module\Review\Command\PublishDocumentHandler;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

#[McpTool(name: 'document_publish', description: 'Send a draft document to review, so it reaches the reviewer\'s inbox. A document that is not a draft is returned unchanged, with published false. An archived document is refused. No new version is created.')]
final readonly class DocumentPublishTool
{
    public function __construct(
        private PublishDocumentHandler $handler,
        private ReviewSubjectResolver $subjects,
        private ToolCallErrorMessages $errorMessages,
    ) {
    }

    /**
     * @param string $documentId The UUID of the draft document to send to review
     *
     * @return array{documentId: string, status: string, published: bool}
     */
    public function __invoke(string $documentId): array
    {
        try {
            $document = $this->subjects->requireDocument($documentId, McpBoundProjectVoter::DOCUMENT_WRITE);

            $published = ($this->handler)(new PublishDocumentCommand($document));

            return ['documentId' => (string) $document->id, 'status' => $document->status->value, 'published' => $published];
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The document could not be published. The error has been logged.', previous: $e);
        }
    }
}
