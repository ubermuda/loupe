<?php

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// ParaTest numbers its workers through TEST_TOKEN, which a worktree's
// .env.test.local also sets. Keep both, so each worker gets its own database.
// A worktree token never holds "__", so "__p<n>" cannot name another worktree.
$paratestWorker = getenv('PARATEST') ? getenv('TEST_TOKEN') : false;
if (false !== $paratestWorker) {
    putenv('TEST_TOKEN');
    unset($_ENV['TEST_TOKEN'], $_SERVER['TEST_TOKEN']);
}

new Dotenv()->bootEnv(dirname(__DIR__).'/.env');

if (false !== $paratestWorker) {
    $token = ($_SERVER['TEST_TOKEN'] ?? '').'__p'.$paratestWorker;
    // Postgres truncates a longer name, so two workers would share one database.
    if (\strlen('app_test'.$token) > 63) {
        throw new RuntimeException(sprintf('The test database name app_test%s is longer than 63 bytes. Use a shorter worktree name.', $token));
    }
    putenv('TEST_TOKEN='.$token);
    $_ENV['TEST_TOKEN'] = $_SERVER['TEST_TOKEN'] = $token;
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Create the test database schema once before the PHPUnit run. DAMA's
// PHPUnitExtension then wraps each test in a rolled-back transaction.
// TEST_SCHEMA_READY skips the reset, which costs about six seconds, for a
// caller that built the schema first and then spawns many PHPUnit processes.
(function (): void {
    if (filter_var(getenv('TEST_SCHEMA_READY'), \FILTER_VALIDATE_BOOL)) {
        return;
    }

    // The ParaTest main process only lists the tests. Its workers reset their own databases.
    if (!getenv('PARATEST') && 'paratest' === basename($_SERVER['argv'][0] ?? '')) {
        return;
    }

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
