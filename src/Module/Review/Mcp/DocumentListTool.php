<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * List documents in the project bound to the authenticated MCP token.
 */
#[McpTool(name: 'document_list', description: 'List documents in the token\'s project, with their current status and version. Paginated: pass page to walk further, and keep going while hasMore is true.')]
final readonly class DocumentListTool
{
    use ResolvesBoundProject;

    public const int DEFAULT_PER_PAGE = 50;

    public const int MAX_PER_PAGE = 100;

    public function __construct(
        private DocumentRepository $documents,
        private DocumentVersionRepository $documentVersions,
        private AuthenticatedProjectResolver $projectResolver,
    ) {
    }

    /**
     * The list is wrapped in a `documents` object key because the MCP spec requires
     * a tool result's `structuredContent` to be a JSON object, not a bare array.
     *
     * `hasMore` is returned alongside the counts so a caller can walk the whole
     * set without computing page arithmetic itself.
     *
     * @return array{documents: list<array{documentId: string, title: string, status: string, currentVersion: int}>, page: int, perPage: int, total: int, hasMore: bool}
     */
    public function __invoke(int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): array
    {
        // Clamped rather than rejected: an out-of-range page from an agent
        // should return an empty page, not fail the tool call.
        $page = max(1, $page);
        $perPage = min(self::MAX_PER_PAGE, max(1, $perPage));

        try {
            $project = $this->requireBoundProject($this->projectResolver);

            $paginator = $this->documents->findPaginatedByProject($project, $page, $perPage);
            $total = \count($paginator);

            /** @var list<Document> $documents */
            $documents = array_values(iterator_to_array($paginator));
            $latestVersions = $this->documentVersions->findLatestMetaByDocuments($documents);

            return [
                'documents' => array_map(
                    static function (Document $doc) use ($latestVersions) {
                        $meta = $latestVersions[(string) $doc->id] ?? throw new \LogicException('Document has no versions.');

                        return [
                            'documentId' => (string) $doc->id,
                            'title' => $doc->title,
                            'status' => $doc->status->value,
                            'currentVersion' => $meta['versionNumber'],
                        ];
                    },
                    $documents,
                ),
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'hasMore' => $page * $perPage < $total,
            ];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The document list could not be read. The error has been logged.', previous: $e);
        }
    }
}
