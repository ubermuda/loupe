<?php

declare(strict_types=1);

namespace App\Observability;

use App\Service\BuildIdentity;
use Sentry\Event;
use Sentry\EventHint;

/**
 * Some paths carry a secret, such as a password reset token, so no URL
 * leaves the instance. The route name in the transaction still identifies
 * the page. A request with no route keeps only its method.
 */
final readonly class SentryEventScrubber
{
    private const array URL_DATA_KEYS = ['http.url', 'http.query', 'http.fragment'];

    private const string EMAIL_PATTERN = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';

    public function __construct(
        private BuildIdentity $buildIdentity,
    ) {
    }

    public function __invoke(Event $event, ?EventHint $hint): Event
    {
        $this->scrubRequest($event);
        $this->scrubTrace($event);
        $this->scrubEmails($event);

        $extra = $event->getExtra();
        unset($extra['Full command']);
        $event->setExtra($extra);

        if (null !== $this->buildIdentity->version) {
            $event->setRelease($this->buildIdentity->version);
        }

        return $event;
    }

    private function scrubRequest(Event $event): void
    {
        $request = $event->getRequest();
        unset($request['url'], $request['query_string']);

        if (isset($request['headers']) && \is_array($request['headers'])) {
            $request['headers'] = array_filter(
                $request['headers'],
                static fn (int|string $name): bool => 'referer' !== strtolower((string) $name),
                \ARRAY_FILTER_USE_KEY,
            );
        }

        $event->setRequest($request);
    }

    private function scrubTrace(Event $event): void
    {
        $transaction = $event->getTransaction();
        if (null !== $transaction && str_contains($transaction, '://')) {
            $method = strtok($transaction, ' ');
            $event->setTransaction(false === $method || str_contains($method, '://') ? '[Filtered]' : $method);
        }

        $trace = $event->getContexts()['trace'] ?? null;
        if (null !== $trace) {
            if (isset($trace['data']) && \is_array($trace['data'])) {
                $trace['data'] = array_diff_key($trace['data'], array_flip(self::URL_DATA_KEYS));
                if ([] === $trace['data']) {
                    unset($trace['data']);
                }
            }
            if (\in_array($trace['op'] ?? null, ['http.server', 'http.client'], true)) {
                unset($trace['description']);
            }
            $event->setContext('trace', $trace);
        }

        foreach ($event->getSpans() as $span) {
            $description = $span->getDescription();
            if (null !== $description) {
                $span->setDescription(match ($span->getOp()) {
                    'http.server' => self::serverDescription($description, $span->getData()),
                    'http.client' => self::clientDescription($description),
                    default => $description,
                });
            }

            // Span::setData() merges and nothing removes a key, so overwrite the value.
            $present = array_intersect_key($span->getData(), array_flip(self::URL_DATA_KEYS));
            if ([] !== $present) {
                $span->setData(array_map(static fn (): string => '[Filtered]', $present));
            }
        }
    }

    /** @param array<string, mixed> $data */
    private static function serverDescription(string $description, array $data): string
    {
        $method = $data['http.request.method'] ?? null;
        if (!\is_string($method)) {
            $method = explode(' ', $description, 2)[0];
        }
        $route = $data['route'] ?? null;

        return \is_string($route) && '' !== $route ? $method.' '.$route : $method;
    }

    private static function clientDescription(string $description): string
    {
        [$method, $url] = explode(' ', $description, 2) + [1 => ''];
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return $method;
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return \sprintf('%s %s://%s%s', $method, $parts['scheme'], $parts['host'], $port);
    }

    private function scrubEmails(Event $event): void
    {
        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::redact($exception->getValue()));
        }

        $message = $event->getMessage();
        if (null !== $message) {
            $formatted = $event->getMessageFormatted();
            $event->setMessage(
                self::redact($message),
                array_map(static fn (mixed $param): mixed => \is_string($param) ? self::redact($param) : $param, $event->getMessageParams()),
                null !== $formatted ? self::redact($formatted) : null,
            );
        }
    }

    private static function redact(string $text): string
    {
        return preg_replace(self::EMAIL_PATTERN, '[email]', $text) ?? '[redacted]';
    }
}
