<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

use Symfony\Component\Uid\Uuid;

/**
 * What a work request knows about its card when it opens: the pull request it
 * acts on and why, and the document it revises. A snapshot, so a later push
 * leaves the head sha behind. A bridge fills prompts and commands with these
 * values, so each one has a strict shape.
 */
final readonly class WorkRequestContext
{
    public const int MAX_PULL_REQUEST_NUMBER = 2147483647;

    public const int MAX_URL_LENGTH = 2000;

    private const string URL_PATTERN = '#^https://[A-Za-z0-9._~:/?\#\[\]@!$&()*+,;=%-]+$#D';

    private const string SHA_PATTERN = '#^[0-9a-f]{7,64}$#D';

    private const string REASON_PATTERN = '/^[a-z][a-z0-9-]{0,63}$/D';

    private const array KEYS = ['pullRequestNumber', 'pullRequestUrl', 'headSha', 'reason', 'documentId'];

    public function __construct(
        public ?int $pullRequestNumber = null,
        public ?string $pullRequestUrl = null,
        public ?string $headSha = null,
        public ?string $reason = null,
        public ?string $documentId = null,
    ) {
        if (null !== $pullRequestNumber && ($pullRequestNumber < 1 || $pullRequestNumber > self::MAX_PULL_REQUEST_NUMBER)) {
            throw new \UnexpectedValueException('A work request names a pull request by a positive 32-bit number.');
        }
        if (null !== $pullRequestUrl && !self::acceptsUrl($pullRequestUrl)) {
            throw new \UnexpectedValueException('A work request names a pull request by an https URL.');
        }
        if (null !== $headSha && !self::acceptsHeadSha($headSha)) {
            throw new \UnexpectedValueException('A work request names a head by a lower-case hex sha.');
        }
        if (null !== $reason && 1 !== preg_match(self::REASON_PATTERN, $reason)) {
            throw new \UnexpectedValueException('A work request reason is a code.');
        }
        if (null !== $documentId && (!Uuid::isValid($documentId) || $documentId !== Uuid::fromString($documentId)->toRfc4122())) {
            throw new \UnexpectedValueException('A work request names a document by its lower-case uuid.');
        }
    }

    public static function acceptsUrl(string $url): bool
    {
        $host = parse_url($url, \PHP_URL_HOST);

        return \strlen($url) <= self::MAX_URL_LENGTH && 1 === preg_match(self::URL_PATTERN, $url) && \is_string($host) && '' !== $host;
    }

    public static function acceptsHeadSha(string $sha): bool
    {
        return 1 === preg_match(self::SHA_PATTERN, $sha);
    }

    /**
     * Reads the stored column. A row from before the column has null.
     *
     * @param array<mixed>|null $data
     */
    public static function fromArray(?array $data): self
    {
        if (null === $data) {
            return new self();
        }
        $keys = array_keys($data);
        sort($keys);
        $expected = self::KEYS;
        sort($expected);
        if ($keys !== $expected) {
            throw new \UnexpectedValueException('A work request context holds exactly the keys '.implode(', ', self::KEYS).'.');
        }

        return new self(
            pullRequestNumber: self::nullableInt($data['pullRequestNumber']),
            pullRequestUrl: self::nullableString($data['pullRequestUrl']),
            headSha: self::nullableString($data['headSha']),
            reason: self::nullableString($data['reason']),
            documentId: self::nullableString($data['documentId']),
        );
    }

    /** @return array{pullRequestNumber: ?int, pullRequestUrl: ?string, headSha: ?string, reason: ?string, documentId: ?string} */
    public function toArray(): array
    {
        return [
            'pullRequestNumber' => $this->pullRequestNumber,
            'pullRequestUrl' => $this->pullRequestUrl,
            'headSha' => $this->headSha,
            'reason' => $this->reason,
            'documentId' => $this->documentId,
        ];
    }

    private static function nullableInt(mixed $value): ?int
    {
        return null === $value || \is_int($value) ? $value : throw new \UnexpectedValueException('A work request context holds a number or null.');
    }

    private static function nullableString(mixed $value): ?string
    {
        return null === $value || \is_string($value) ? $value : throw new \UnexpectedValueException('A work request context holds a string or null.');
    }
}
