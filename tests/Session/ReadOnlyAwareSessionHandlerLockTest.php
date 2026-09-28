<?php

declare(strict_types=1);

namespace App\Tests\Session;

use App\Observability\RequestTimeline;
use App\Session\PdoSessionHandlerFactory;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

/**
 * These handlers open their own connections, outside the test transaction, so
 * each test uses a fresh session id and deletes its row in tearDown.
 */
final class ReadOnlyAwareSessionHandlerLockTest extends KernelTestCase
{
    private const string SEEDED = 'seeded-data';

    private PdoSessionHandlerFactory $factory;

    private string $sessionId;

    private ?PdoSessionHandler $holder = null;

    #[\Override]
    protected function setUp(): void
    {
        $factory = static::getContainer()->get(PdoSessionHandlerFactory::class);
        self::assertInstanceOf(PdoSessionHandlerFactory::class, $factory);
        $this->factory = $factory;
        $this->sessionId = 'lock-test-'.bin2hex(random_bytes(8));

        $seeder = $this->factory->create(PdoSessionHandler::LOCK_NONE);
        $seeder->open('', 'PHPSESSID');
        $seeder->write($this->sessionId, self::SEEDED);
        $seeder->close();

        $this->holder = $this->factory->create(PdoSessionHandler::LOCK_TRANSACTIONAL);
        $this->holder->open('', 'PHPSESSID');
        self::assertSame(self::SEEDED, $this->holder->read($this->sessionId));
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->holder?->close();
        $this->holder = null;

        $cleaner = $this->factory->create(PdoSessionHandler::LOCK_NONE);
        self::connectionOf($cleaner)->prepare('DELETE FROM sessions WHERE sess_id = ?')->execute([$this->sessionId]);

        parent::tearDown();
    }

    public function test_a_second_locking_read_waits_for_the_lock(): void
    {
        $second = $this->factory->create(PdoSessionHandler::LOCK_TRANSACTIONAL);
        self::failFastOnLocks($second);
        $second->open('', 'PHPSESSID');

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('lock timeout');

        try {
            $second->read($this->sessionId);
        } finally {
            $second->close();
        }
    }

    public function test_a_read_only_request_reads_through_the_lock_and_writes_nothing(): void
    {
        $request = Request::create('/projects/x/inbox/open-count');
        $request->attributes->set(ReadOnlyAwareSessionHandler::READ_ONLY, true);
        $handler = $this->handlerFor($request);

        $handler->open('', 'PHPSESSID');

        self::assertSame(self::SEEDED, $handler->read($this->sessionId));
        // A real write would wait on the held row lock and hit lock_timeout.
        self::assertTrue($handler->write($this->sessionId, 'changed'));
        self::assertTrue($handler->close());

        $this->releaseHolder();

        self::assertSame(self::SEEDED, $this->storedData());
    }

    public function test_a_safe_request_reads_through_the_lock_and_still_writes(): void
    {
        $handler = $this->handlerFor(Request::create('/projects/x/board'));

        $handler->open('', 'PHPSESSID');

        self::assertSame(self::SEEDED, $handler->read($this->sessionId));

        $this->releaseHolder();

        self::assertTrue($handler->write($this->sessionId, 'changed'));
        self::assertTrue($handler->close());

        self::assertSame('changed', $this->storedData());
    }

    private function handlerFor(Request $request): ReadOnlyAwareSessionHandler
    {
        $locking = $this->factory->create(PdoSessionHandler::LOCK_TRANSACTIONAL);
        $nonLocking = $this->factory->create(PdoSessionHandler::LOCK_NONE);
        self::failFastOnLocks($locking);
        self::failFastOnLocks($nonLocking);

        $requests = new RequestStack();
        $requests->push($request);

        $timeline = static::getContainer()->get(RequestTimeline::class);
        self::assertInstanceOf(RequestTimeline::class, $timeline);

        return new ReadOnlyAwareSessionHandler($locking, $nonLocking, $requests, $timeline);
    }

    private function releaseHolder(): void
    {
        $this->holder?->close();
        $this->holder = null;
    }

    private function storedData(): string
    {
        $reader = $this->factory->create(PdoSessionHandler::LOCK_NONE);
        $statement = self::connectionOf($reader)->prepare('SELECT sess_data FROM sessions WHERE sess_id = ?');
        $statement->execute([$this->sessionId]);
        $data = $statement->fetchColumn();

        return is_resource($data) ? (string) stream_get_contents($data) : (string) $data;
    }

    private static function failFastOnLocks(PdoSessionHandler $handler): void
    {
        self::connectionOf($handler)->exec("SET lock_timeout = '500ms'");
    }

    private static function connectionOf(PdoSessionHandler $handler): \PDO
    {
        $connection = new \ReflectionMethod($handler, 'getConnection')->invoke($handler);
        self::assertInstanceOf(\PDO::class, $connection);

        return $connection;
    }
}
