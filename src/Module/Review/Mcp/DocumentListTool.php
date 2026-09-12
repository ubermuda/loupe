<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Series;
use App\Module\Review\Entity\Tag;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * List documents in the project bound to the authenticated MCP token.
 */
#[McpTool(name: 'document_list', description: 'List documents in the token\'s project, with their current status and version. Each row carries that version\'s description, which says what the document is about, so you rarely need document_get to find the one you want. Each row also carries its tags and, when it has one, its series and position in it. Narrow the list with search for full-text terms, and with status, tag and series. A search orders the rows by relevance instead of by recency, and a series orders them by position. Archived documents are omitted unless includeArchived is true. Paginated: pass page to walk further, and keep going while hasMore is true.')]
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
     * @param int         $page            The 1-based page to read
     * @param int         $perPage         How many documents to return per page
     * @param bool        $includeArchived Include archived documents, which are omitted by default
     * @param string|null $search          Full-text terms matched against every document's title and current content, stemmed in the language each document is stored in. Quotes and OR work as in a web search box. Matching rows come back by relevance rather than by recency.
     * @param string|null $status          Keep only documents in this state: in-review, approved or changes-requested
     * @param string|null $tag             Keep only documents carrying this tag; matched ignoring case and surrounding spaces. Read tag_list for the project's vocabulary.
     * @param string|null $series          Keep only documents in this series, matched ignoring case, and order them by their position in it. Read series_list for the project's names.
     *
     * @return array{documents: list<array{documentId: string, title: string, status: string, currentVersion: int, versionDescription: ?string, archived: bool, tags: list<string>, series: ?string, seriesOrdinal: ?int}>, page: int, perPage: int, total: int, hasMore: bool}
     */
    public function __invoke(int $page = 1, int $perPage = self::DEFAULT_PER_PAGE, bool $includeArchived = false, ?string $search = null, ?string $status = null, ?string $tag = null, ?string $series = null): array
    {
        // Clamped rather than rejected: an out-of-range page from an agent
        // should return an empty page, not fail the tool call.
        $page = max(1, $page);
        $perPage = min(self::MAX_PER_PAGE, max(1, $perPage));

        try {
            $project = $this->requireBoundProject($this->projectResolver);

            $paginator = $this->documents->findPaginatedByProject(
                $project,
                $page,
                $perPage,
                $includeArchived,
                $this->blankToNull($search),
                $this->optionalStatus($status),
                // The repository matches the stored spelling of both, so a raw
                // argument would filter to nothing rather than fail.
                $this->blankToNull($tag, Tag::normalizeName(...)),
                $this->blankToNull($series, Series::normalizeName(...)),
            );
            $total = \count($paginator);

            /** @var list<Document> $documents */
            $documents = array_values(iterator_to_array($paginator));
            $latestVersions = $this->documentVersions->findLatestMetaByDocuments($documents);
            // Tags are a lazy collection, so reading one per row would cost a
            // query per row. Hydrates the whole page in one.
            $this->documents->preloadTags($documents);

            return [
                'documents' => array_map(
                    static function (Document $doc) use ($latestVersions) {
                        $meta = $latestVersions[(string) $doc->id] ?? throw new \LogicException('Document has no versions.');

                        $tags = [];
                        foreach ($doc->tags as $tag) {
                            $tags[] = $tag->name;
                        }

                        return [
                            'documentId' => (string) $doc->id,
                            'title' => $doc->title,
                            'status' => $doc->status->value,
                            'currentVersion' => $meta['versionNumber'],
                            'versionDescription' => $meta['description'],
                            'archived' => null !== $doc->archivedAt,
                            'tags' => $tags,
                            'series' => $doc->series?->name,
                            'seriesOrdinal' => $doc->seriesOrdinal,
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

    /**
     * A filter the caller left blank, or spelled with spaces alone, means "do
     * not filter". Passing it on would return an empty list instead.
     *
     * @param ?callable(string): string $normalize
     */
    private function blankToNull(?string $value, ?callable $normalize = null): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = null === $normalize ? trim($value) : $normalize($value);

        return '' === $value ? null : $value;
    }

    /**
     * Refused rather than dropped, unlike the web list: a hand-edited URL should
     * show every document, but an agent that misspells a status has to learn
     * that the rows it got back were never narrowed.
     */
    private function optionalStatus(?string $status): ?DocumentStatus
    {
        if (null === $status || '' === trim($status)) {
            return null;
        }

        return DocumentStatus::tryFrom($status)
            ?? throw new ToolCallException(\sprintf('Unknown status "%s". Use one of: %s.', $status, implode(', ', DocumentStatus::values())));
    }
}
