<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Validator\NotReservedUsernameValidator;

final readonly class UsernameGenerator
{
    public function __construct(
        private UserRepository $users,
    ) {
    }

    /**
     * Derives a free username from a preferred handle (a GitHub login, or the
     * email local part), appending a random hex suffix until it is free. The
     * result matches the shape the registration form accepts — lowercase, at
     * least three characters, starting with a letter, at most 30 characters —
     * and is never one of the reserved names.
     *
     * @return non-empty-string
     */
    public function fromPreferred(string $preferred): string
    {
        $base = strtolower((string) preg_replace('/[^a-z0-9_-]+/i', '-', trim($preferred)));
        $base = trim(substr($base, 0, 24), '-_');

        if (1 !== preg_match('/^[a-z][a-z0-9_-]{2,}$/', $base) || in_array($base, NotReservedUsernameValidator::RESERVED, true)) {
            $base = 'user';
        }

        $candidate = $base;
        while (null !== $this->users->findOneByUsername($candidate)) {
            $candidate = $base.'-'.bin2hex(random_bytes(2));
        }

        return $candidate;
    }
}
