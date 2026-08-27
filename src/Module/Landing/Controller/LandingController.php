<?php

declare(strict_types=1);

namespace App\Module\Landing\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\ShowHomeCommand;
use App\Module\Project\Command\ShowHomeHandler;
use App\Module\Project\Mcp\AdvertisedTools;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

#[Route('/', name: 'app_home')]
class LandingController extends AppController
{
    public const string ENABLED_FLAG = 'landing.enabled';

    public function __construct(
        private readonly ShowHomeHandler $showHome,
        private readonly FeatureFlagService $featureFlags,
        private readonly AdvertisedTools $advertisedTools,

        #[Autowire(param: 'app.demo_command')]
        private readonly string $demoCommand,

        #[Autowire(param: 'app.compose_excerpt')]
        private readonly string $composeExcerpt,

        #[Autowire(param: 'app.hosted_price')]
        private readonly string $hostedPrice,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            // Seeded off, so an instance nobody has told otherwise keeps
            // sending anonymous visitors to the login form, as it did before
            // this page existed.
            if (!$this->featureFlags->isEnabled(self::ENABLED_FLAG)) {
                return $this->redirectToRoute('app_login');
            }

            return $this->render('@Landing/landing.html.twig', [
                'demoCommand' => $this->demoCommand,
                'composeExcerpt' => $this->composeExcerpt,
                'mcpTools' => $this->advertisedTools->enabled(),
                'hostedPrice' => $this->hostedPrice,
            ]);
        }

        $projects = ($this->showHome)(new ShowHomeCommand($user))->projects;

        if ([] === $projects && null === $user->wizardCompletedAt) {
            return $this->redirectToRoute('app_welcome');
        }

        if (1 === \count($projects)) {
            return $this->redirectToRoute('app_project_documents', ['id' => (string) $projects[0]->id]);
        }

        return $this->redirectToRoute('app_projects');
    }
}
