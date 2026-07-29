<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final readonly class RegistrationGate
{
    public const string CAP_FLAG = 'registration.cap';

    /** Master switch: off closes sign-up entirely, whatever the cap says. */
    public const string ENABLED_FLAG = 'registration.enabled';

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
        private InstallationState $installationState,
    ) {
    }

    /**
     * Whether new accounts may be created at all — the master switch plus a
     * completed install, neither of which `isOpen()` covers. The install check
     * is the one that matters: while the users table is empty, whoever
     * registers first becomes the instance's owner, so the first account must
     * come from the wizard or `app:admin:create`.
     */
    public function allowsNewAccounts(): bool
    {
        // Default true: the row exists only on wizard-seeded instances, and an
        // instance upgraded from a version without this flag must keep
        // registering users as before. The fresh-deploy hole is closed by the
        // installation check below, not by this default.
        if (!$this->featureFlags->isEnabled(self::ENABLED_FLAG, true)) {
            return false;
        }

        return !$this->installationState->isOpen();
    }

    /**
     * Capacity only — whether a registration-cap slot is free. Says nothing
     * about whether sign-up is allowed; that is `allowsNewAccounts()`. Billing
     * calls this one on purpose: a disabled account re-subscribing needs a free
     * slot, not permission to register.
     */
    public function isOpen(): bool
    {
        $cap = $this->featureFlags->getIntValue(self::CAP_FLAG, 0);
        if ($cap <= 0) {
            return true;
        }

        return $this->users->countActive() < $cap;
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
