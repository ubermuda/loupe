<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

#[McpTool(name: 'site_review_get', description: 'Fetch site-review comments (DOM-anchored feedback captured in the browser) for the project bound to your MCP token. Returns the unaddressed ones by default. Address each comment, then mark it with site_review_mark_comment_addressed; pass status to read back the ones you already addressed, which the default view no longer shows.')]
final readonly class SiteReviewGetTool
{
    use ResolvesBoundProject;

    public function __construct(
        private ProjectRepository $projects,
        private SiteReviewCommentRepository $siteReviewComments,
        private AuthenticatedProjectResolver $projectResolver,
    ) {
    }

    /**
     * @param string|null $site   optional site id or site name; must match the project your MCP token is bound to
     * @param string|null $status which comments to return: pending (the default), addressed, resolved, or all
     *
     * @return array{site: array{id: string, name: string}, comments: list<array{id: string, url: string, selector: string, text: string, body: string, status: string, createdAt: string}>}
     */
    public function __invoke(?string $site = null, ?string $status = null): array
    {
        try {
            $project = $this->requireBoundProject($this->projectResolver);

            if (null !== $site) {
                $resolved = $this->projects->findOneByIdOrNameForOwner($site, $project->owner);

                if ($resolved !== $project) {
                    throw new ToolCallException(\sprintf('Site "%s" not found or not accessible.', $site));
                }
            }

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
                        'selector' => $c->selector,
                        'text' => $c->text,
                        'body' => $c->body,
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
