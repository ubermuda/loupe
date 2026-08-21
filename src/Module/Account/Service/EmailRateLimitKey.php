<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds the limiter key for a per-recipient email bucket.
 *
 * Keyed rather than a plain digest: limiter keys are persisted by the
 * doctrine_dbal cache pool, and a bare hash of an address is guessable from a
 * wordlist, so a plain digest would put recoverable addresses in the database —
 * the problem a per-recipient bucket is not supposed to introduce.
 *
 * Lowercased and trimmed first because `findOneByEmail` is case-insensitive:
 * without it, `Victim@example.com` gets a different bucket to the account it
 * targets and the limit is bypassed by changing case.
 */
final readonly class EmailRateLimitKey
{
    public function __construct(
        #[Autowire(param: 'kernel.secret')]
        private string $secret,
    ) {
    }

    public function __invoke(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), $this->secret);
    }
}
