<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\SiteReview\Command\ListedSite;
use App\Module\SiteReview\Command\ListSitesCommand;
use App\Module\SiteReview\Command\ListSitesHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lists the caller's projects for the loupe CLI, which checks a token with it
 * and names the valid slugs when a rule file names an unknown project. Each
 * project says what the bridge collects from the tool calls of its runs.
 *
 * Agent-scoped tokens only. The firewall grants this path to
 * ROLE_API_AGENT alone, so a project-bound widget token gets 403
 * `insufficient_scope` before this class runs. A widget token is embedded in
 * public page HTML, and its contract is one project's comments and nothing
 * else; enumerating the owner's projects would leak the inventory to any page
 * visitor. Project ownership is enforced by the handler's owner-scoped lookup.
 */
#[Route(
    '/api/projects',
    name: 'api_projects',
    methods: ['GET'],
)]
final class ListSitesController extends AppController
{
    public function __construct(
        private readonly ListSitesHandler $listSites,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Sites endpoint reached without an authenticated User.');
        }

        $view = ($this->listSites)(new ListSitesCommand($user));

        return $this->json(['sites' => array_values(array_map(
            static fn (ListedSite $site): array => [
                'id' => (string) $site->project->id,
                'slug' => $site->project->slug,
                'name' => $site->project->name,
                'collectFullText' => $site->collectFullText,
                'subcommandPrograms' => $site->subcommandPrograms,
            ],
            $view->sites,
        ))]);
    }
}
