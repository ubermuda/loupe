<?php

declare(strict_types=1);

namespace App\Tests\Session;

use App\Session\PdoSessionHandlerFactory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class PdoSessionHandlerFactoryTest extends TestCase
{
    public function test_it_takes_the_database_name_from_doctrine_rather_than_the_url(): void
    {
        // The regression this guards: doctrine.yaml appends a dbname_suffix per
        // worktree and under PHPUnit, so DATABASE_URL names `app` while the
        // sessions table was migrated into `app_wt_feature`. Building the
        // handler from the raw URL makes every worktree share the main
        // database's sessions — silently, because that table does exist there.
        $factory = new PdoSessionHandlerFactory(
            $this->connectionTo('app_wt_feature'),
            'postgresql://app:s3cret@database:5432/app?serverVersion=16&charset=utf8',
        );

        self::assertSame('pgsql:host=database;port=5432;dbname=app_wt_feature', $factory->sessionDsn());
    }

    public function test_it_carries_sslmode_onto_the_session_connection(): void
    {
        // A managed Postgres that requires TLS would otherwise refuse only the
        // session connection, while Doctrine and the cache connect fine.
        $factory = new PdoSessionHandlerFactory(
            $this->connectionTo('app'),
            'postgresql://app:s3cret@db.example.com:25060/app?sslmode=require&serverVersion=16',
        );

        self::assertSame('pgsql:host=db.example.com;port=25060;dbname=app;sslmode=require', $factory->sessionDsn());
    }

    public function test_it_drops_doctrine_only_parameters_that_libpq_would_reject(): void
    {
        $factory = new PdoSessionHandlerFactory(
            $this->connectionTo('app'),
            'postgresql://database/app?serverVersion=16&charset=utf8',
        );

        self::assertSame('pgsql:host=database;dbname=app', $factory->sessionDsn());
    }

    public function test_it_rejects_a_url_it_cannot_read(): void
    {
        $factory = new PdoSessionHandlerFactory($this->connectionTo('app'), 'not-a-url');

        $this->expectException(\LogicException::class);

        $factory->sessionDsn();
    }

    public function test_it_rejects_a_database_that_is_not_postgres(): void
    {
        $factory = new PdoSessionHandlerFactory($this->connectionTo('app'), 'mysql://app@database:3306/app');

        $this->expectException(\LogicException::class);

        $factory->sessionDsn();
    }

    private function connectionTo(string $database): Connection&Stub
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabase')->willReturn($database);

        return $connection;
    }
}
