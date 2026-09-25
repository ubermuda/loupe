<?php

declare(strict_types=1);

namespace App\Tests\Mercure;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\LiveUpdates;
use App\Tests\Support\FeatureFlags;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

final class LiveUpdatePublisherTest extends TestCase
{
    private RequestStack $requests;
    private TestHandler $log;

    /** @var list<Update> */
    private array $published = [];

    protected function setUp(): void
    {
        $this->requests = new RequestStack();
        $this->log = new TestHandler();
    }

    public function test_it_publishes_a_private_json_update_only_when_asked_to(): void
    {
        $publisher = $this->publisher();

        $publisher->queue('https://loupe.test/board/1', ['type' => 'board.columns_changed']);
        self::assertSame([], $this->sent());
        $publisher->publish();

        self::assertSame([[['https://loupe.test/board/1'], '{"type":"board.columns_changed","origin":null}']], $this->sent());
        foreach ($this->published as $update) {
            self::assertTrue($update->isPrivate());
        }
    }

    public function test_with_live_updates_off_it_neither_builds_the_hub_nor_publishes(): void
    {
        $publisher = new LiveUpdatePublisher(
            $this->requests,
            FeatureFlags::service([LiveUpdates::FLAG => false]),
            new Logger('test', [$this->log]),
            static fn (): HubInterface => throw new \LogicException('the hub must not be built with live updates off'),
        );

        $publisher->queue('https://loupe.test/board/1', ['type' => 'board.columns_changed']);
        $publisher->publish();

        self::assertSame([], $this->log->getRecords());
    }

    public function test_it_sends_one_update_per_topic_and_payload(): void
    {
        $publisher = $this->publisher();

        $publisher->queue('https://loupe.test/board/1', ['type' => 'a']);
        $publisher->queue('https://loupe.test/board/1', ['type' => 'a']);
        $publisher->queue('https://loupe.test/board/1', ['type' => 'b']);
        $publisher->queue('https://loupe.test/board/2', ['type' => 'a']);
        $publisher->publish();

        self::assertSame([
            [['https://loupe.test/board/1'], '{"type":"a","origin":null}'],
            [['https://loupe.test/board/1'], '{"type":"b","origin":null}'],
            [['https://loupe.test/board/2'], '{"type":"a","origin":null}'],
        ], $this->sent());
    }

    public function test_a_publish_sends_each_update_once(): void
    {
        $publisher = $this->publisher();
        $publisher->queue('https://loupe.test/board/1', ['type' => 'a']);

        $publisher->publish();
        $publisher->publish();

        self::assertCount(1, $this->published);
    }

    public function test_it_stamps_the_origin_header_of_the_main_request(): void
    {
        $this->requests->push($this->request("  tab-1\t"));
        $this->requests->push($this->request('sub-request'));
        $publisher = $this->publisher();

        $publisher->queue('https://loupe.test/board/1', ['type' => 'a']);
        $publisher->publish();

        self::assertSame('{"type":"a","origin":"tab-1"}', $this->published[0]->getData());
    }

    public function test_it_cuts_a_long_origin_to_64_characters(): void
    {
        $this->requests->push($this->request(str_repeat('é', 70)));
        $publisher = $this->publisher();

        $publisher->queue('https://loupe.test/board/1', ['type' => 'a']);
        $publisher->publish();

        self::assertSame(['type' => 'a', 'origin' => str_repeat('é', 64)], $this->payload(0));
    }

    /** @return iterable<string, array{?string}> */
    public static function noOrigin(): iterable
    {
        yield 'no header' => [null];
        yield 'an empty header' => [''];
        yield 'a blank header' => ['   '];
        yield 'a header that is not UTF-8' => ["\xC3\x28"];
    }

    #[DataProvider('noOrigin')]
    public function test_a_request_without_a_usable_origin_stamps_null(?string $header): void
    {
        $this->requests->push($this->request($header));
        $publisher = $this->publisher();

        $publisher->queue('https://loupe.test/board/1', ['type' => 'a']);
        $publisher->publish();

        self::assertSame(['type' => 'a', 'origin' => null], $this->payload(0));
    }

    public function test_a_payload_that_names_its_origin_keeps_it(): void
    {
        $this->requests->push($this->request('tab-1'));
        $publisher = $this->publisher();

        $publisher->queue('https://loupe.test/board/1', ['type' => 'a', 'origin' => 'agent']);
        $publisher->publish();

        self::assertSame(['type' => 'a', 'origin' => 'agent'], $this->payload(0));
    }

    public function test_two_changes_from_two_origins_send_two_updates(): void
    {
        $publisher = $this->publisher();

        $this->requests->push($this->request('tab-1'));
        $publisher->queue('https://loupe.test/board/1', ['type' => 'a']);
        $this->requests->pop();
        $this->requests->push($this->request('tab-2'));
        $publisher->queue('https://loupe.test/board/1', ['type' => 'a']);
        $publisher->publish();

        self::assertSame([
            [['https://loupe.test/board/1'], '{"type":"a","origin":"tab-1"}'],
            [['https://loupe.test/board/1'], '{"type":"a","origin":"tab-2"}'],
        ], $this->sent());
    }

    public function test_a_hub_that_fails_is_logged_and_the_rest_still_publish(): void
    {
        $publisher = new LiveUpdatePublisher(
            $this->requests,
            FeatureFlags::service([LiveUpdates::FLAG => true]),
            new Logger('test', [$this->log]),
            fn (): HubInterface => new MockHub(
                'http://mercure/.well-known/mercure',
                new StaticTokenProvider('token'),
                function (Update $update): string {
                    if (['https://loupe.test/board/1'] === $update->getTopics()) {
                        throw new \RuntimeException('hub unreachable');
                    }
                    $this->published[] = $update;

                    return 'id';
                },
            ),
        );

        $publisher->queue('https://loupe.test/board/1', ['type' => 'a']);
        $publisher->queue('https://loupe.test/board/2', ['type' => 'a']);
        $publisher->publish();

        self::assertSame([[['https://loupe.test/board/2'], '{"type":"a","origin":null}']], $this->sent());
        self::assertTrue($this->log->hasWarning([
            'message' => 'live_updates.publish_failed',
            'context' => ['topic' => 'https://loupe.test/board/1', 'error' => 'hub unreachable'],
        ]));
    }

    public function test_a_reset_drops_what_is_queued(): void
    {
        $publisher = $this->publisher();
        $publisher->queue('https://loupe.test/board/1', ['type' => 'a']);

        $publisher->reset();
        $publisher->publish();

        self::assertSame([], $this->published);
    }

    private function publisher(): LiveUpdatePublisher
    {
        return new LiveUpdatePublisher(
            $this->requests,
            FeatureFlags::service([LiveUpdates::FLAG => true]),
            new Logger('test', [$this->log]),
            fn (): HubInterface => new MockHub(
                'http://mercure/.well-known/mercure',
                new StaticTokenProvider('token'),
                function (Update $update): string {
                    $this->published[] = $update;

                    return 'id';
                },
            ),
        );
    }

    private function request(?string $origin): Request
    {
        $request = Request::create('/');
        if (null !== $origin) {
            $request->headers->set('X-Loupe-Origin', $origin);
        }

        return $request;
    }

    /** @return array<mixed> */
    private function payload(int $index): array
    {
        $payload = json_decode($this->published[$index]->getData(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    /** @return list<array{array<mixed>, string}> */
    private function sent(): array
    {
        return array_map(
            static fn (Update $update): array => [$update->getTopics(), $update->getData()],
            $this->published,
        );
    }
}
