<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller;

use App\Controller\AppController;
use App\Module\Bridge\Command\ShowInstallScriptCommand;
use App\Module\Bridge\Command\ShowInstallScriptHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Serves the CLI install script to `curl | sh`. Its own firewall has no security, so no session makes the answer private. */
#[Route(
    '/install.sh',
    name: 'app_install_script',
    methods: ['GET'],
)]
final class ShowInstallScriptController extends AppController
{
    public function __construct(
        private readonly ShowInstallScriptHandler $showInstallScript,
    ) {
    }

    public function __invoke(): Response
    {
        $loupeUrl = rtrim($this->generateUrl('app_home', referenceType: UrlGeneratorInterface::ABSOLUTE_URL), '/');

        $response = new Response(($this->showInstallScript)(new ShowInstallScriptCommand($loupeUrl)), Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=utf-8']);
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }
}
