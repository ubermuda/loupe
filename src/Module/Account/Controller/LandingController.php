<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Command\ShowHomeCommand;
use App\Module\Account\Command\ShowHomeHandler;
use App\Module\Account\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

#[Route('/', name: 'app_home')]
class LandingController extends AppController
{
    public function __construct(
        private readonly ShowHomeHandler $showHome,
        private readonly FeatureFlagService $featureFlags,

        #[Autowire(param: 'app.demo_command')]
        private readonly string $demoCommand,

        #[Autowire(param: 'app.hosted_price')]
        private readonly string $hostedPrice,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            // The marketing page sells a hosted plan, so it is wrong on an
            // instance someone runs themselves. `billing.enabled` is the closest
            // thing to "this is the hosted instance" the app knows about.
            if (!$this->featureFlags->isEnabled('billing.enabled')) {
                return $this->redirectToRoute('app_login');
            }

            return $this->render('@Account/landing.html.twig', [
                'demoCommand' => $this->demoCommand,
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
