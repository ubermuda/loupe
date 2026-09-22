<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Sends `/account`, and the `?tab=` links of the old one-page layout, to a section. */
#[Route(
    '/account',
    name: 'app_account_settings',
    methods: ['GET'],
)]
class ShowAccountSettingsController extends AppController
{
    private const array SECTION_ROUTES = [
        'connected-apps' => 'app_account_connected_apps',
        'data' => 'app_account_data',
    ];

    public function __invoke(Request $request): Response
    {
        return $this->redirectToRoute(self::SECTION_ROUTES[$request->query->getString('tab')] ?? 'app_account_profile');
    }
}
