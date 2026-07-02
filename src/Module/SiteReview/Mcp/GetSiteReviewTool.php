<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Mcp;

use App\Module\Account\Entity\User;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Repository\SiteRepository;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\SecurityBundle\Security;

#[McpTool(name: 'get_site_review', description: 'Fetch all unaddressed site-review comments (DOM-anchored feedback captured in the browser) for one of your sites, by site id or site name. Address each comment, then mark it with address_site_review_comments.')]
final readonly class GetSiteReviewTool
{
    public function __construct(
        private SiteRepository $sites,
        private SiteReviewCommentRepository $siteReviewComments,
        private Security $security,
    ) {
    }

    /**
     * @param string $site the site id or site name shown in the Better Plans sites page
     *
     * @return array{site: array{id: string, name: string}, comments: list<array{id: string, url: string, selector: string, text: string, body: string, reviewId: string, submittedAt: ?string}>}
     */
    public function __invoke(string $site): array
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $resolved = $this->sites->findOneByIdOrNameForOwner($site, $user)
            ?? throw new ToolCallException(\sprintf('No site "%s" found.', $site));

        $pending = $this->siteReviewComments->findPendingForSite($resolved);

        return [
            'site' => ['id' => (string) $resolved->id, 'name' => $resolved->name],
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
