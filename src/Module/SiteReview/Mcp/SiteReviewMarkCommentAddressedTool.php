<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Uid\Uuid;

/**
 * The agent's only write: Pending → Addressed. Resolved is reserved for the
 * human in the web UI.
 *
 * What keeps it that way is routing and firewall configuration, not
 * authorization. An MCP request authenticates *as the project owner*, and
 * SiteReviewCommentVoter's entire rule is `$subject->project->owner ===
 * $token->getUser()` — so the voter would grant the resolve attribute to a
 * tool call. Two other things stop it: no tool calls the resolve path, and
 * ApiTokenAuthenticator is registered only on the `mcp` and `api` firewalls,
 * so a Bearer token cannot authenticate against the resolve route on `main`
 * at all (that route additionally carries a session-backed CSRF token).
 *
 * The consequence for anyone adding a tool: every ownership-based voter in
 * this app returns true for an MCP request by construction, so a voter result
 * is not a meaningful check on what an agent may do.
 */
#[McpTool(name: 'site_review_mark_comment_addressed', description: 'Mark site-review comments as addressed after fixing them. Accepts the comment ids returned by site_review_get. Comments that are unknown, already addressed, or resolved are skipped, not fatal.')]
final readonly class SiteReviewMarkCommentAddressedTool
{
    use ResolvesBoundProject;

    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
        private AuthenticatedProjectResolver $projectResolver,
    ) {
    }

    /**
     * `string[]` not `list<string>`: the SDK infers a parameter's JSON-schema
     * `items` from the docblock type and parses only the `T[]` and `array<T>`
     * spellings, so `list<string>` publishes an array of anything.
     *
     * @param string[] $commentIds comment ids from site_review_get
     *
     * @return array{addressed: list<string>, skipped: list<array{id: string, reason: string}>}
     */
    public function __invoke(array $commentIds): array
    {
        $addressed = [];
        $skipped = [];

        try {
            $project = $this->requireBoundProject($this->projectResolver);

            foreach ($commentIds as $id) {
                try {
                    $uuid = Uuid::fromString($id);
                } catch (\InvalidArgumentException) {
                    $skipped[] = ['id' => $id, 'reason' => 'invalid_id'];
                    continue;
                }

                $comment = $this->siteReviewComments->findOneForProject($uuid, $project);
                if (null === $comment) {
                    $skipped[] = ['id' => $id, 'reason' => 'unknown'];
                    continue;
                }
                if (SiteReviewCommentStatus::Pending !== $comment->status) {
                    $skipped[] = ['id' => $id, 'reason' => match ($comment->status) {
                        SiteReviewCommentStatus::Addressed => 'already_addressed',
                        default => 'resolved',
                    }];
                    continue;
                }

                // The status check above is advisory: it produces the precise
                // skip reason, but a human can click Resolve between it and the
                // write. Only the conditional UPDATE decides.
                if (!$this->siteReviewComments->markAddressedIfPending($comment)) {
                    $skipped[] = ['id' => $id, 'reason' => 'resolved'];
                    continue;
                }

                $addressed[] = $id;
            }
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The comments could not be marked as addressed. The error has been logged.', previous: $e);
        }

        return ['addressed' => $addressed, 'skipped' => $skipped];
    }
}
