<?php

declare(strict_types=1);

namespace App\Mercure;

use App\Outbox\AgentPush;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\Authorization;
use Symfony\Contracts\Service\ResetInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * The Mercure topics one request asks for, from every module on the page. The
 * authorizers filter them once, and SetMercureCookieOnResponse puts the topics
 * they allow into the single subscriber cookie the hub reads.
 */
final class MercureSubscriptions implements ResetInterface
{
    /** @var list<string> */
    private array $requested = [];

    /** @var list<string>|null */
    private ?array $allowed = null;

    /**
     * @param iterable<MercureTopicAuthorizerInterface> $authorizers
     * @param \Closure(): Authorization                 $authorization a closure, because
     *                                                                 building it reads
     *                                                                 MERCURE_JWT_SECRET
     */
    public function __construct(
        #[AutowireIterator(MercureTopicAuthorizerInterface::TAG)]
        private readonly iterable $authorizers,
        private readonly FeatureFlagService $featureFlags,
        private readonly RequestStack $requests,
        private readonly LoggerInterface $logger,

        #[AutowireServiceClosure(Authorization::class)]
        private readonly \Closure $authorization,

        #[Autowire(env: 'MERCURE_PUBLIC_URL')]
        public readonly string $hubUrl,
    ) {
    }

    public function request(string $topic): void
    {
        if (!\in_array($topic, $this->requested, true)) {
            $this->requested[] = $topic;
            $this->allowed = null;
        }
    }

    /** @return list<string> */
    public function allowedTopics(): array
    {
        return $this->allowed ??= $this->authorize();
    }

    /** @return list<string> */
    private function authorize(): array
    {
        // A refused form forwards to its page, and the bundle only writes a
        // cookie that sits on the main request.
        $request = $this->requests->getMainRequest();
        if ([] === $this->requested || null === $request || '' === $this->hubUrl || !$this->featureFlags->isEnabled(AgentPush::FLAG)) {
            return [];
        }

        $allowed = array_values(array_filter($this->requested, $this->isAllowed(...)));
        if ([] === $allowed) {
            return [];
        }

        try {
            ($this->authorization)()->createCookie($request, $allowed);
        } catch (\Throwable $e) {
            // A hub on another site cannot read a cookie this host sets.
            $this->logger->warning('mercure.authorization_failed', ['error' => $e->getMessage()]);

            return [];
        }

        return $allowed;
    }

    private function isAllowed(string $topic): bool
    {
        foreach ($this->authorizers as $authorizer) {
            $decision = $authorizer->mayCurrentUserSubscribe($topic);
            if (null !== $decision) {
                if (!$decision) {
                    $this->logger->info('mercure.topic_refused', ['topic' => $topic]);
                }

                return $decision;
            }
        }

        $this->logger->info('mercure.topic_unclaimed', ['topic' => $topic]);

        return false;
    }

    #[\Override]
    public function reset(): void
    {
        $this->requested = [];
        $this->allowed = null;
    }
}
