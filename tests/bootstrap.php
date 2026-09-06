<?php

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

new Dotenv()->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Create the test database schema once before the PHPUnit run.
// DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension then wraps each test
// in a transaction that is rolled back, providing isolation without the
// overhead of dropping and recreating the schema for every test.
(function (): void {
    $kernel = new App\Kernel('test', (bool) ($_SERVER['APP_DEBUG'] ?? false));

    // test.log then holds exactly one run, which a date-based rotation cannot
    // give. test.deprecation.log is left alone: a phpunit run writes nothing to
    // it, so truncating it would wipe what a console run found.
    $mainLog = $kernel->getLogDir().'/test.log';
    if (is_file($mainLog)) {
        file_put_contents($mainLog, '');
    }

    $kernel->boot();

    $app = new Application($kernel);
    $app->setAutoExit(false);
    $app->setCatchExceptions(false);

    $app->run(new ArrayInput(['command' => 'doctrine:database:drop', '--if-exists' => '1', '--force' => '1']));
    $app->run(new ArrayInput(['command' => 'doctrine:database:create', '--if-not-exists' => '1']));
    $app->run(new ArrayInput(['command' => 'doctrine:migrations:migrate', '--no-interaction' => '1']));

    $kernel->shutdown();
})();
