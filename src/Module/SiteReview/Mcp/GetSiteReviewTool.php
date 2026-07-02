<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Mcp;

use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

#[McpTool(name: 'get_site_review', description: 'Fetch all unaddressed site-review comments (DOM-anchored feedback captured in the browser) for the project bound to your MCP token. Address each comment, then mark it with address_site_review_comments.')]
final readonly class GetSiteReviewTool
{
    public function __construct(
        private ProjectRepository $projects,
        private SiteReviewCommentRepository $siteReviewComments,
        private AuthenticatedProjectResolver $projectResolver,
    ) {
    }

    /**
     * @param string|null $site optional site id or site name; must match the project your MCP token is bound to
     *
     * @return array{site: array{id: string, name: string}, comments: list<array{id: string, url: string, selector: string, text: string, body: string, reviewId: string, submittedAt: ?string}>}
     */
    public function __invoke(?string $site = null): array
    {
        $project = $this->projectResolver->resolveMcpProject();
        if (null === $project) {
            throw new ToolCallException('MCP token is not bound to a project. Mint a project token from the Connect page.');
        }

        if (null !== $site) {
            $resolved = $this->projects->findOneByIdOrNameForOwner($site, $project->owner)
                ?? throw new ToolCallException(\sprintf('No site "%s" found.', $site));

            if ($resolved !== $project) {
                throw new ToolCallException('Token is not bound to that project.');
            }
        }

        $pending = $this->siteReviewComments->findPendingForProject($project);

        return [
            'site' => ['id' => (string) $project->id, 'name' => $project->name],
            'comments' => array_values(array_map(
                static fn (SiteReviewComment $c): array => [
                    'id' => (string) $c->id,
                    'url' => $c->url,
                    'selector' => $c->selector,
                    'text' => $c->text,
                    'body' => $c->body,
                    'reviewId' => (string) $c->review->id,
                    'submittedAt' => $c->review->submittedAt?->format(\DateTimeInterface::ATOM),
                ],
                $pending,
            )),
        ];
    }
}
