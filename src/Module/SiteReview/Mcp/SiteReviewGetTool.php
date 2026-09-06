<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Mcp;

use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentAnchor;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

#[McpTool(name: 'site_review_get', description: 'Fetch site-review comments (DOM-anchored feedback captured in the browser) for the project bound to your MCP token. Returns the unaddressed ones by default. Address each comment, then mark it with site_review_mark_comment_addressed; pass status to read back the ones you already addressed, which the default view no longer shows.')]
final readonly class SiteReviewGetTool
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
        private SiteReviewSubjectResolver $subjects,
    ) {
    }

    /**
     * @param string|null $site   optional site id or site name; must match the project your MCP token is bound to
     * @param string|null $status which comments to return: pending (the default), addressed, resolved, or all
     *
     * @return array{site: array{id: string, name: string}, comments: list<array{id: string, url: string, anchors: list<array{selector: string, text: string, quote: string|null, quotePrefix: string|null, quoteSuffix: string|null}>, body: string, hasDrawing: bool, status: string, createdAt: string}>}
     */
    public function __invoke(?string $site = null, ?string $status = null): array
    {
        try {
            $project = $this->subjects->requireProject($site);

            // Marking a comment addressed used to put it beyond every read path
            // in this server, so an agent could not report on, or revisit, its
            // own work.
            $comments = match ($status) {
                null, 'pending' => $this->siteReviewComments->findPendingForProject($project),
                'all' => $this->siteReviewComments->findForProject($project),
                'addressed' => $this->siteReviewComments->findForProjectWithStatus($project, SiteReviewCommentStatus::Addressed),
                'resolved' => $this->siteReviewComments->findForProjectWithStatus($project, SiteReviewCommentStatus::Resolved),
                default => throw new ToolCallException(\sprintf('Unknown status "%s". Use pending, addressed, resolved or all.', $status)),
            };

            return [
                'site' => ['id' => (string) $project->id, 'name' => $project->name],
                'comments' => array_values(array_map(
                    static fn (SiteReviewComment $c): array => [
                        'id' => (string) $c->id,
                        'url' => $c->url,
                        'anchors' => array_values(array_map(
                            static fn (SiteReviewCommentAnchor $a): array => [
                                'selector' => $a->selector,
                                'text' => $a->text,
                                'quote' => $a->quote,
                                'quotePrefix' => $a->quotePrefix,
                                'quoteSuffix' => $a->quoteSuffix,
                            ],
                            $c->anchors->toArray(),
                        )),
                        'body' => $c->body,
                        // The strokes themselves are vector points over a live
                        // page, which an agent cannot render or act on. The
                        // flag says the comment points at something the words
                        // may not name, so ask the reviewer rather than guess.
                        'hasDrawing' => null !== $c->strokes && [] !== $c->strokes,
                        'status' => $c->status->value,
                        'createdAt' => $c->createdAt->format(\DateTimeInterface::ATOM),
                    ],
                    $comments,
                )),
            ];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The site review could not be read. The error has been logged.', previous: $e);
        }
    }
}
