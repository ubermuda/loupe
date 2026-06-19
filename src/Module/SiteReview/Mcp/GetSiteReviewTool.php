<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Mcp;

use App\Module\Account\Entity\User;
use App\Module\SiteReview\Query\BatchNotFound;
use App\Module\SiteReview\Query\GetSiteReview;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * Fetch a batch of site-review comments by its id.
 */
#[McpTool(name: 'get_site_review', description: 'Fetch a batch of site-review annotations (DOM-anchored comments captured in the browser) by its batch id.')]
final readonly class GetSiteReviewTool
{
    public function __construct(
        private GetSiteReview $getSiteReview,
        private Security $security,
    ) {
    }

    /**
     * @param string $batchId the batch id shown in the widget after sending
     *
     * @return array{createdAt: string, comments: list<array{url: string, selector: string, text: string, body: string}>}
     */
    public function __invoke(string $batchId): array
    {
        /** @var User $user */
        $user = $this->security->getUser();

        try {
            return ($this->getSiteReview)(Uuid::fromString($batchId), $user);
        } catch (BatchNotFound $e) {
            throw new ToolCallException($e->getMessage(), previous: $e);
        } catch (\InvalidArgumentException $e) {
            throw new ToolCallException(\sprintf('"%s" is not a valid batch id.', $batchId), previous: $e);
        }
    }
}
