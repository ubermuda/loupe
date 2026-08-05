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

        self::assertSame('postgresql://app:s3cret@database:5432/app_wt_feature', $factory->sessionUrl());
    }

    public function test_it_keeps_credentials_and_port_from_the_url(): void
    {
        $factory = new PdoSessionHandlerFactory(
            $this->connectionTo('app'),
            'postgresql://someone:p%40ss@db.example.com:6432/app',
        );

        self::assertSame('postgresql://someone:p%40ss@db.example.com:6432/app', $factory->sessionUrl());
    }

    public function test_it_omits_credentials_when_the_url_carries_none(): void
    {
        $factory = new PdoSessionHandlerFactory($this->connectionTo('app'), 'postgresql://database/app');

        self::assertSame('postgresql://database/app', $factory->sessionUrl());
    }

    public function test_it_rejects_a_url_it_cannot_read(): void
    {
        $factory = new PdoSessionHandlerFactory($this->connectionTo('app'), 'not-a-url');

        $this->expectException(\LogicException::class);

        $factory->sessionUrl();
    }

    private function connectionTo(string $database): Connection&Stub
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabase')->willReturn($database);

        return $connection;
    }
}
