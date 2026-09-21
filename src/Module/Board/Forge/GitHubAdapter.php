<?php

declare(strict_types=1);

namespace App\Module\Board\Forge;

use App\Module\Board\Entity\Forge;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * GitHub's half of the receiver.
 *
 * It serves a GitHub App and a hook a person configured in repository settings
 * alike, because both sign the body the same way. The App is a convenience on a
 * hosted instance rather than a dependency anywhere.
 */
final readonly class GitHubAdapter implements ForgeAdapterInterface
{
    private const string SIGNATURE_HEADER = 'X-Hub-Signature-256';
    private const string EVENT_HEADER = 'X-GitHub-Event';

    public function __construct(
        #[Autowire(env: 'GITHUB_WEBHOOK_SECRET')]
        private string $webhookSecret,
    ) {
    }

    #[\Override]
    public function forge(): Forge
    {
        return Forge::GitHub;
    }

    #[\Override]
    public function translate(Request $request): array
    {
        $body = $request->getContent();
        $this->verify($body, $request->headers->get(self::SIGNATURE_HEADER, ''));

        $payload = json_decode($body, true);
        if (!\is_array($payload)) {
            throw new InvalidForgeSignature('the body is not a JSON object');
        }

        return match ($request->headers->get(self::EVENT_HEADER, '')) {
            'pull_request' => $this->fromPullRequest($payload),
            'pull_request_review' => $this->fromReview($payload),
            'check_suite' => $this->fromCheckSuite($payload),
            'repository' => $this->fromRename($payload),
            default => [],
        };
    }

    /**
     * An unset secret refuses every delivery. A webhook that trusts an empty
     * string would accept anything a stranger sends.
     */
    private function verify(string $body, string $signature): void
    {
        if ('' === $this->webhookSecret) {
            throw new InvalidForgeSignature('no webhook secret is configured');
        }

        $expected = 'sha256='.hash_hmac('sha256', $body, $this->webhookSecret);
        if (!hash_equals($expected, $signature)) {
            throw new InvalidForgeSignature('the signature does not match the body');
        }
    }

    /**
     * A merge has no event of its own. It arrives here as `closed` with the
     * merged flag set, and every other action is noise the lifecycle ignores.
     *
     * @param array<mixed> $payload
     *
     * @return list<ForgeDelivery>
     */
    private function fromPullRequest(array $payload): array
    {
        $pullRequest = $payload['pull_request'] ?? null;
        if ('closed' !== ($payload['action'] ?? null) || !\is_array($pullRequest) || true !== ($pullRequest['merged'] ?? null)) {
            return [];
        }

        return $this->one(ForgeEventType::MERGED, $payload, $pullRequest);
    }

    /**
     * @param array<mixed> $payload
     *
     * @return list<ForgeDelivery>
     */
    private function fromReview(array $payload): array
    {
        $pullRequest = $payload['pull_request'] ?? null;
        if ('submitted' !== ($payload['action'] ?? null) || !\is_array($pullRequest)) {
            return [];
        }

        return $this->one(ForgeEventType::REVIEW_SUBMITTED, $payload, $pullRequest);
    }

    /**
     * A check suite is the aggregate of the run. A check run fires per check,
     * and this repository gates on thirteen of them.
     *
     * @param array<mixed> $payload
     *
     * @return list<ForgeDelivery>
     */
    private function fromCheckSuite(array $payload): array
    {
        $suite = $payload['check_suite'] ?? null;
        if ('completed' !== ($payload['action'] ?? null) || !\is_array($suite)) {
            return [];
        }

        $deliveries = [];
        foreach ($suite['pull_requests'] ?? [] as $pullRequest) {
            if (\is_array($pullRequest)) {
                $deliveries = [...$deliveries, ...$this->one(ForgeEventType::CHECKS_CONCLUDED, $payload, $pullRequest)];
            }
        }

        return $deliveries;
    }

    /**
     * A rename changes the path every later delivery joins on. GitHub reports
     * the old name in `changes`, which is the only place it appears.
     *
     * Only `renamed` is handled. A transfer reports its old owner in a shape
     * this adapter has never seen a real delivery of, and guessing it would
     * repoint links onto a path nobody owns.
     *
     * @param array<mixed> $payload
     *
     * @return list<ForgeDelivery>
     */
    private function fromRename(array $payload): array
    {
        $to = $this->repositoryOf($payload);
        $from = $payload['changes']['repository']['name']['from'] ?? null;
        $owner = $payload['repository']['owner']['login'] ?? null;
        if ('renamed' !== ($payload['action'] ?? null) || null === $to) {
            return [];
        }

        if (!\is_string($from) || '' === $from || !\is_string($owner) || '' === $owner) {
            return [];
        }

        $old = $owner.'/'.$from;

        return $old === $to ? [] : [new ForgeDelivery(ForgeEventType::REPOSITORY_MOVED, Forge::GitHub, $old, movedTo: $to)];
    }

    /**
     * @param array<mixed> $payload
     * @param array<mixed> $pullRequest
     *
     * @return list<ForgeDelivery>
     */
    private function one(string $type, array $payload, array $pullRequest): array
    {
        $repository = $this->repositoryOf($payload);
        $number = $pullRequest['number'] ?? null;
        if (null === $repository || !\is_int($number) || $number <= 0) {
            return [];
        }

        return [new ForgeDelivery($type, Forge::GitHub, $repository, $number)];
    }

    /** @param array<mixed> $payload */
    private function repositoryOf(array $payload): ?string
    {
        $full = $payload['repository']['full_name'] ?? null;

        return \is_string($full) && '' !== $full ? $full : null;
    }
}
