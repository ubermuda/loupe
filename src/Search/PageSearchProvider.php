<?php

declare(strict_types=1);

namespace App\Search;

use App\Module\Project\Entity\Project;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final readonly class PageSearchProvider implements SearchProviderInterface
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private FeatureFlagService $flags,
    ) {
    }

    #[\Override]
    public function search(Project $project, string $query, int $page): SearchResults
    {
        if (1 !== $page) {
            return new SearchResults();
        }
        $routes = [
            'app_project_workshop' => 'nav.link.workshop',
            'app_project_documents' => 'nav.link.documents',
            'app_project_site_review' => 'nav.link.site_review',
            'app_project_agents' => 'nav.link.agents',
            'app_project_worker_runs' => 'nav.link.worker_runs',
            'app_project_activity' => 'nav.link.activity',
            'app_project_outbox' => 'nav.link.outbox',
            'app_project_edit' => 'nav.link.project_settings',
            'app_projects' => 'nav.switcher.all_projects',
            'app_account_profile' => 'nav.link.account',
        ];
        if ($this->flags->isEnabled('board.enabled')) {
            $routes['app_project_board'] = 'nav.link.board';
            $routes['app_project_rules'] = 'nav.link.rules';
        }
        if ($this->flags->isEnabled('inbox.enabled')) {
            $routes['app_project_inbox'] = 'nav.link.inbox';
        }
        $items = [];
        foreach ($routes as $route => $label) {
            $title = $this->translator->trans($label);
            if ('' === $query || false !== mb_stripos($title, $query)) {
                $parameters = in_array($route, ['app_projects', 'app_account_profile'], true) ? [] : ['id' => (string) $project->id];
                $items[] = new SearchResult($title, $this->urls->generate($route, $parameters), 'page');
            }
        }

        return new SearchResults($items);
    }
}
