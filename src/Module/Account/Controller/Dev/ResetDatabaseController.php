<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Command\ResetDatabaseCommand;
use App\Module\Account\Command\ResetDatabaseHandler;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only: wipes all application data so the install-wizard e2e spec can run
 * against a blank database. Never registered outside the dev environment.
 */
#[Route(
    '/dev/e2e/reset',
    name: 'app_dev_e2e_reset',
    methods: ['POST'],
)]
#[When('dev')]
final class ResetDatabaseController extends AppController
{
    public function __construct(
        private readonly ResetDatabaseHandler $resetDatabase,

        #[Autowire(param: 'kernel.environment')]
        private readonly string $environment,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        // Defense-in-depth on top of #[When('dev')]: this truncates every table, so
        // it additionally requires the header Playwright sends on every request
        // (extraHTTPHeaders in playwright.config.ts) — a drive-by request against a
        // reachable dev deployment cannot set a custom header cross-origin, so this
        // rules out the accidental/CSRF-style trigger that #[When('dev')] alone does
        // not.
        if ('dev' !== $this->environment || !$request->headers->has('X-Playwright')) {
            throw $this->createNotFoundException();
        }

        ($this->resetDatabase)(new ResetDatabaseCommand());

        return new JsonResponse(['ok' => true]);
    }
}
