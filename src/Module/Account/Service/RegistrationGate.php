<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final readonly class RegistrationGate
{
    // Kept for call sites (tests) that construct a FeatureFlag entity by
    // name directly. isOpen() below deliberately does NOT use this constant
    // — see the comment there — so this value must be kept in sync with the
    // literal used in that call.
    public const string CAP_FLAG = 'registration.cap';

    /**
     * Arbitrary app-unique Postgres advisory-lock key. Shared by the form
     * registration handler and the OAuth registration branch so a capacity
     * decision under one path can never race a capacity decision under the
     * other.
     */
    public const int CAPACITY_LOCK = 4_821_0001;

    public function __construct(
        private FeatureFlagService $featureFlags,
        private UserRepository $users,
    ) {
    }

    public function isOpen(): bool
    {
        // The scanner that powers the admin "undefined flags" page and orphan
        // cleanup only recognizes a string literal as the first argument here
        // — using the self::CAP_FLAG constant would make it invisible to that
        // tooling and eligible for deletion as orphaned. self::CAP_FLAG stays
        // available for other call sites (tests) that construct a
        // FeatureFlag by name directly.
        $cap = $this->featureFlags->getIntValue('registration.cap', 0);
        if ($cap <= 0) {
            return true;
        }

        return $this->users->countAll() < $cap;
    }

    /**
     * Serializes every registration-capacity decision (form and OAuth) behind
     * one Postgres transaction-scoped advisory lock, so two concurrent
     * sign-ups can never both pass a one-slot gate. Must be called from
     * inside the caller's `wrapInTransaction()` closure — the lock is
     * released automatically at transaction end.
     */
    public function acquireCapacityLock(Connection $connection): void
    {
        $connection->executeStatement('SELECT pg_advisory_xact_lock(:key)', ['key' => self::CAPACITY_LOCK]); // @translation-check-ignore
    }
}
