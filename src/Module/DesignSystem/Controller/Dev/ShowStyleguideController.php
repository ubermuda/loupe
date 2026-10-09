<?php

declare(strict_types=1);

namespace App\Module\DesignSystem\Controller\Dev;

use App\Controller\AppController;
use App\Module\DesignSystem\Command\ShowStyleguideCommand;
use App\Module\DesignSystem\Command\ShowStyleguideHandler;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only page that draws every design token and every catalog entry from the
 * real stylesheet and components. #[When('dev')] keeps the route out of every
 * other environment. The firewall lets it through, so no account is needed.
 */
#[Route(
    '/styleguide',
    name: 'app_dev_styleguide',
    methods: ['GET'],
)]
#[When('dev')]
final class ShowStyleguideController extends AppController
{
    public function __construct(
        private readonly ShowStyleguideHandler $showStyleguide,
    ) {
    }

    public function __invoke(): Response
    {
        $view = ($this->showStyleguide)(new ShowStyleguideCommand());

        return $this->render('@DesignSystem/show_styleguide.html.twig', [
            'groups' => $view->groups,
            'components' => $view->components,
        ]);
    }
}
