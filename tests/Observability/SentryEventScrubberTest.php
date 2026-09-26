<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\SentryEventScrubber;
use App\Service\BuildIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionDataBag;
use Sentry\Stacktrace;
use Sentry\State\Hub;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;

final class SentryEventScrubberTest extends TestCase
{
    private string $projectDir;

    #[\Override]
    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/sentry-scrubber-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/var', 0o777, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->projectDir.'/var/build-version');
        rmdir($this->projectDir.'/var');
        rmdir($this->projectDir);
    }

    public function test_the_request_keeps_no_url(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://loupe.example/forgot-password/reset/secret-token?a=b',
            'method' => 'POST',
            'query_string' => 'a=b',
            'headers' => [
                'Referer' => ['https://loupe.example/forgot-password/reset/secret-token'],
                'Accept' => ['text/html'],
            ],
        ]);

        $scrubbed = $this->scrubber()($event, null);

        self::assertSame(['method' => 'POST', 'headers' => ['Accept' => ['text/html']]], $scrubbed->getRequest());
    }

    public function test_the_referer_header_goes_whatever_its_case(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['method' => 'GET', 'headers' => ['referer' => ['https://loupe.example/x']]]);

        $scrubbed = $this->scrubber()($event, null);

        self::assertSame(['method' => 'GET', 'headers' => []], $scrubbed->getRequest());
    }

    public function test_the_request_keeps_only_allowlisted_headers_whatever_their_case(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['method' => 'GET', 'headers' => [
            'Host' => ['loupe.example'],
            'user-agent' => ['curl/8'],
            'ACCEPT' => ['*/*'],
            'Accept-Language' => ['en'],
            'Content-Type' => ['text/plain'],
            'Content-Length' => ['3'],
            'X-Probe-Token' => ['probe-secret'],
            'Authorization' => ['[Filtered]'],
            'Cookie' => ['[Filtered]'],
            'X-Forwarded-For' => ['203.0.113.7'],
            'X-Custom' => ['anything'],
        ]]);

        $scrubbed = $this->scrubber()($event, null);

        self::assertSame(['method' => 'GET', 'headers' => [
            'Host' => ['loupe.example'],
            'user-agent' => ['curl/8'],
            'ACCEPT' => ['*/*'],
            'Accept-Language' => ['en'],
            'Content-Type' => ['text/plain'],
            'Content-Length' => ['3'],
        ]], $scrubbed->getRequest());
    }

    public function test_the_trace_context_keeps_the_route_and_loses_the_url(): void
    {
        $event = Event::createTransaction();
        $event->setTransaction('POST app_reset_password');
        $event->setContext('trace', [
            'span_id' => 'abc',
            'trace_id' => 'def',
            'data' => [
                'http.url' => 'https://loupe.example/forgot-password/reset/secret-token',
                'http.request.method' => 'POST',
                'route' => 'app_reset_password',
            ],
        ]);

        $scrubbed = $this->scrubber()($event, null);

        self::assertSame('POST app_reset_password', $scrubbed->getTransaction());
        self::assertSame(
            ['http.request.method' => 'POST', 'route' => 'app_reset_password'],
            $scrubbed->getContexts()['trace']['data'],
        );
    }

    public function test_a_transaction_named_by_its_url_keeps_only_its_method(): void
    {
        $event = Event::createTransaction();
        $event->setTransaction('POST https://loupe.example/forgot-password/reset/secret-token');

        $scrubbed = $this->scrubber()($event, null);

        self::assertSame('POST', $scrubbed->getTransaction());
    }

    #[DataProvider('bareUrlNames')]
    public function test_a_transaction_named_by_a_url_alone_is_filtered(string $name): void
    {
        $event = Event::createTransaction();
        $event->setTransaction($name);

        $scrubbed = $this->scrubber()($event, null);

        self::assertSame('[Filtered]', $scrubbed->getTransaction());
    }

    /** @return iterable<string, array{string}> */
    public static function bareUrlNames(): iterable
    {
        yield 'no method' => ['https://loupe.example/forgot-password/reset/secret-token'];
        yield 'leading space' => [' https://loupe.example/forgot-password/reset/secret-token'];
    }

    public function test_a_transaction_named_by_a_class_keeps_its_name(): void
    {
        $event = Event::createTransaction();
        $event->setTransaction('App\Module\Mail\Messenger\SendDigest');

        $scrubbed = $this->scrubber()($event, null);

        self::assertSame('App\Module\Mail\Messenger\SendDigest', $scrubbed->getTransaction());
    }

    public function test_a_span_loses_its_url_query_and_fragment(): void
    {
        $span = new Span(SpanContext::make()->setOp('http.client')->setData([
            'http.url' => 'https://api.example/v1/users',
            'http.query' => 'email=bob%40example.com',
            'http.fragment' => 'top',
            'http.request.method' => 'GET',
        ]));
        $event = Event::createTransaction();
        $event->setSpans([$span]);

        $scrubbed = $this->scrubber()($event, null);

        self::assertSame([
            'http.url' => '[Filtered]',
            'http.query' => '[Filtered]',
            'http.fragment' => '[Filtered]',
            'http.request.method' => 'GET',
        ], $scrubbed->getSpans()[0]->getData());
    }

    public function test_a_sub_request_span_is_named_by_its_route(): void
    {
        $span = new Span(SpanContext::make()
            ->setOp('http.server')
            ->setDescription('GET https://loupe.example/forgot-password/reset/secret-token')
            ->setData(['http.request.method' => 'GET', 'route' => 'app_reset_password']));
        $event = Event::createTransaction();
        $event->setSpans([$span]);

        $scrubbed = $this->scrubber()($event, null);

        self::assertSame('GET app_reset_password', $scrubbed->getSpans()[0]->getDescription());
    }

    public function test_a_sub_request_span_without_a_route_keeps_only_its_method(): void
    {
        $span = new Span(SpanContext::make()
            ->setOp('http.server')
            ->setDescription('POST https://loupe.example/forgot-password/reset/secret-token'));
        $event = Event::createTransaction();
        $event->setSpans([$span]);

        $scrubbed = $this->scrubber()($event, null);

        self::assertSame('POST', $scrubbed->getSpans()[0]->getDescription());
    }

    public function test_an_outbound_span_keeps_only_its_method_and_origin(): void
    {
        $span = new Span(SpanContext::make()
            ->setOp('http.client')
            ->setDescription('GET https://api.example:8443/v1/users/bob@example.com')
            ->setData(['http.request.method' => 'GET']));
        $event = Event::createTransaction();
        $event->setSpans([$span]);

        $scrubbed = $this->scrubber()($event, null);

        self::assertSame('GET https://api.example:8443', $scrubbed->getSpans()[0]->getDescription());
    }

    public function test_a_span_of_another_op_keeps_its_description(): void
    {
        $span = new Span(SpanContext::make()->setOp('db.sql.query')->setDescription('SELECT 1'));
        $event = Event::createTransaction();
        $event->setSpans([$span]);

        $scrubbed = $this->scrubber()($event, null);

        self::assertSame('SELECT 1', $scrubbed->getSpans()[0]->getDescription());
    }

    public function test_an_http_trace_context_loses_its_description(): void
    {
        $event = Event::createEvent();
        $event->setContext('trace', [
            'span_id' => 'abc',
            'trace_id' => 'def',
            'op' => 'http.server',
            'description' => 'GET https://loupe.example/forgot-password/reset/secret-token',
        ]);

        $scrubbed = $this->scrubber()($event, null);

        self::assertSame(
            ['span_id' => 'abc', 'trace_id' => 'def', 'op' => 'http.server'],
            $scrubbed->getContexts()['trace'],
        );
    }

    public function test_the_console_command_line_leaves_the_extras(): void
    {
        $event = Event::createEvent();
        $event->setExtra([
            'Full command' => "'app:create-admin' 'bob@example.com' 'hunter2'",
            'kept' => 'value',
        ]);

        $scrubbed = $this->scrubber()($event, null);

        self::assertSame(['kept' => 'value'], $scrubbed->getExtra());
    }

    public function test_a_span_without_url_data_gains_none(): void
    {
        $span = new Span(SpanContext::make()->setOp('db.sql.query')->setData(['db.system' => 'postgresql']));
        $event = Event::createTransaction();
        $event->setSpans([$span]);

        $scrubbed = $this->scrubber()($event, null);

        self::assertSame(['db.system' => 'postgresql'], $scrubbed->getSpans()[0]->getData());
    }

    public function test_no_stack_frame_keeps_its_arguments(): void
    {
        $scrubber = $this->scrubber();
        $before = 0;
        $scrubbed = [];
        $client = ClientBuilder::create([
            'dsn' => 'https://key@o0.ingest.example/1',
            'default_integrations' => false,
            'attach_stacktrace' => true,
            'before_send' => static function (Event $event, ?EventHint $hint) use ($scrubber, &$before, &$scrubbed): Event {
                $before += self::framesWithVars($event);

                return $scrubbed[] = $scrubber($event, $hint);
            },
        ])->setTransport(new readonly class implements TransportInterface {
            #[\Override]
            public function send(Event $event): Result
            {
                return new Result(ResultStatus::success(), $event);
            }

            #[\Override]
            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        })->getClient();
        $hub = new Hub($client);

        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            self::failWith('bob@example.com', 'secret-token');
        } catch (\RuntimeException $exception) {
            $hub->captureException($exception);
        } finally {
            ini_set('zend.exception_ignore_args', false === $previous ? '1' : $previous);
        }
        self::captureMessageWith($hub, 'bob@example.com', 'secret-token');

        self::assertGreaterThanOrEqual(2, $before);
        self::assertCount(2, $scrubbed);
        foreach ($scrubbed as $event) {
            self::assertSame(0, self::framesWithVars($event));
        }
    }

    public function test_emails_leave_the_exceptions_and_the_message(): void
    {
        $event = Event::createEvent();
        $event->setExceptions([
            new ExceptionDataBag(new \RuntimeException('No account for Bob.Smith+x@mail.example.co.uk.')),
        ]);
        $event->setMessage('Mail to %s failed', ['bob@example.com'], 'Mail to bob@example.com failed');

        $scrubbed = $this->scrubber()($event, null);

        self::assertSame('No account for [email].', $scrubbed->getExceptions()[0]->getValue());
        self::assertSame('Mail to %s failed', $scrubbed->getMessage());
        self::assertSame(['[email]'], $scrubbed->getMessageParams());
        self::assertSame('Mail to [email] failed', $scrubbed->getMessageFormatted());
    }

    public function test_an_http_client_exception_keeps_only_the_origin_of_its_url(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 404]));
        try {
            $client->request('GET', 'https://api.example:8443/reset/secret-token?key=s3cr3t#frag')->getContent();
            self::fail('The 404 response did not throw.');
        } catch (ClientExceptionInterface $exception) {
        }
        $event = Event::createEvent();
        $event->setExceptions([new ExceptionDataBag($exception)]);
        $event->setMessage('Call to %s failed', ['http://user:pw@api.example/a?b=c'], "Call to 'http://user:pw@api.example/a?b=c' failed");

        $scrubbed = $this->scrubber()($event, null);

        self::assertSame('HTTP 404 returned for "https://api.example:8443".', $scrubbed->getExceptions()[0]->getValue());
        self::assertSame(['http://api.example'], $scrubbed->getMessageParams());
        self::assertSame("Call to 'http://api.example' failed", $scrubbed->getMessageFormatted());
    }

    public function test_an_event_with_no_message_gets_none(): void
    {
        $scrubbed = $this->scrubber()(Event::createEvent(), null);

        self::assertNull($scrubbed->getMessage());
    }

    public function test_the_build_version_becomes_the_release(): void
    {
        file_put_contents($this->projectDir.'/var/build-version', "v1.2.3\n");
        $event = Event::createEvent();
        $event->setRelease('ignored');

        $scrubbed = $this->scrubber()($event, null);

        self::assertSame('v1.2.3', $scrubbed->getRelease());
    }

    public function test_no_build_version_leaves_the_release_alone(): void
    {
        $event = Event::createEvent();
        $event->setRelease('from-options');

        $scrubbed = $this->scrubber()($event, null);

        self::assertSame('from-options', $scrubbed->getRelease());
    }

    private static function failWith(string $email, string $token): never
    {
        throw new \RuntimeException('Sign-in failed.');
    }

    private static function captureMessageWith(Hub $hub, string $email, string $token): void
    {
        $hub->captureMessage('Sign-in failed.');
    }

    private static function framesWithVars(Event $event): int
    {
        $stacktraces = array_map(static fn (ExceptionDataBag $exception): ?Stacktrace => $exception->getStacktrace(), $event->getExceptions());
        $stacktraces[] = $event->getStacktrace();

        $count = 0;
        foreach (array_filter($stacktraces) as $stacktrace) {
            foreach ($stacktrace->getFrames() as $frame) {
                $count += [] === $frame->getVars() ? 0 : 1;
            }
        }

        return $count;
    }

    private function scrubber(): SentryEventScrubber
    {
        return new SentryEventScrubber(new BuildIdentity($this->projectDir));
    }
}
