<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final readonly class RegistrationGate
{
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
        $cap = $this->featureFlags->getIntValue(self::CAP_FLAG, 0);
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
        $connection->executeStatement('SELECT pg_advisory_xact_lock(:key)', ['key' => self::CAPACITY_LOCK]);
    }
}
