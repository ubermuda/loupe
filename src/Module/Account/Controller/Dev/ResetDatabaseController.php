<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Dev;

use App\Controller\AppController;
use Doctrine\DBAL\Connection;
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
        private readonly Connection $conn,

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

        // Every application table is wiped; the list is derived from the live
        // schema so newly added tables can never silently escape the reset.
        /** @var list<string> $tables */
        $tables = $this->conn->fetchFirstColumn(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'doctrine_migration_versions'",
        );

        if ([] !== $tables) {
            $quoted = array_map(
                $this->conn->quoteIdentifier(...),
                $tables,
            );
            $this->conn->executeStatement('TRUNCATE TABLE '.implode(', ', $quoted).' CASCADE');
        }

        return new JsonResponse(['ok' => true]);
    }
}
