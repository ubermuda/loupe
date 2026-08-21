<?php

namespace App\Module\Account\Repository;

use App\Module\Account\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface, UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->findOneBy(['email' => strtolower($identifier)]);
        // The agent account is never a principal. What actually makes it
        // unreachable is its null password plus the fact that it owns no
        // project and so can never own an API token; this only closes the
        // form-login path, which is the one that comes through here.
        if (!$user instanceof User || $user->isAgent()) {
            throw new UserNotFoundException(sprintf('User "%s" not found.', $identifier));
        }

        return $user;
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }
        $user->password = $newHashedPassword;
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function findByEmailVerificationToken(string $token): ?User
    {
        $hash = hash('sha256', $token);

        return $this->findOneBy(['emailVerificationTokenHash' => $hash]);
    }

    public function findByPasswordResetToken(string $token): ?User
    {
        $hash = hash('sha256', $token);

        return $this->findOneBy(['passwordResetTokenHash' => $hash]);
    }

    public function findByAccountDeletionToken(string $token): ?User
    {
        $hash = hash('sha256', $token);

        return $this->findOneBy(['accountDeletionTokenHash' => $hash]);
    }

    public function findOneByEmail(?string $email): ?User
    {
        if (null === $email) {
            return null;
        }

        return $this->findOneBy(['email' => strtolower($email)]);
    }

    /**
     * Users who currently occupy a registration-cap spot. Disabled accounts
     * (trial ended unconverted, or subscription canceled) do not count — their
     * spot is exactly what the trial-end sweep frees up.
     */
    public function countActive(): int
    {
        return $this->countHumans(activeOnly: true);
    }

    /**
     * Every account a person could sign into. The agent is excluded because the
     * migration that inserts it runs before anyone registers: counted, it would
     * make a brand-new install look already populated and lock the install
     * wizard out permanently.
     */
    public function countHumans(bool $activeOnly = false): int
    {
        $qb = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.id != :agent')
            ->setParameter('agent', User::AGENT_ID);

        if ($activeOnly) {
            $qb->andWhere('u.disabledAt IS NULL');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Administrators who could still log in. The agent is excluded for the same
     * reason countHumans() excludes it, and a suspended admin does not count —
     * the suspension gate pins them out of the admin area entirely.
     */
    public function countActiveAdmins(): int
    {
        $sql = <<<'SQL'
            SELECT COUNT(*) FROM users
            WHERE id != :agent
              AND suspended_at IS NULL
              AND jsonb_exists(roles::jsonb, 'ROLE_ADMIN')
            SQL;

        return (int) $this->getEntityManager()->getConnection()
            ->fetchOne($sql, ['agent' => User::AGENT_ID]);
    }

    /**
     * The admin user list. The agent is excluded here rather than by a filter
     * so no call site can forget: it authors agent-written content across the
     * app and is not a person an admin acts on.
     *
     * $sort is interpolated into DQL and is only safe because ListPageRequest
     * validates it against ListUsersHandler::ALLOWED_SORTS first. Every filter
     * value is bound as a parameter.
     *
     * @param array{q?: string, verified?: string, state?: string, role?: string} $filters
     *
     * @return Paginator<User>
     */
    public function findPaginatedForAdmin(int $page, int $perPage, string $sort, string $dir, array $filters): Paginator
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.id != :agent')
            ->setParameter('agent', User::AGENT_ID)
            ->orderBy('u.'.$sort, 'asc' === $dir ? 'ASC' : 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        $query = $filters['q'] ?? '';
        if ('' !== $query) {
            // Grouped explicitly so the OR can never escape the agent
            // exclusion, whatever Doctrine's Andx does with a raw string.
            $qb->andWhere('(LOWER(u.fullName) LIKE :q OR LOWER(u.email) LIKE :q)')
                ->setParameter('q', '%'.mb_strtolower($query).'%');
        }

        if ('yes' === ($filters['verified'] ?? '')) {
            $qb->andWhere('u.emailVerifiedAt IS NOT NULL');
        } elseif ('no' === ($filters['verified'] ?? '')) {
            $qb->andWhere('u.emailVerifiedAt IS NULL');
        }

        if ('active' === ($filters['state'] ?? '')) {
            $qb->andWhere('u.suspendedAt IS NULL')->andWhere('u.disabledAt IS NULL');
        } elseif ('suspended' === ($filters['state'] ?? '')) {
            $qb->andWhere('u.suspendedAt IS NOT NULL');
        } elseif ('disabled' === ($filters['state'] ?? '')) {
            $qb->andWhere('u.disabledAt IS NOT NULL');
        }

        $role = $filters['role'] ?? '';
        if ('admin' === $role || 'user' === $role) {
            $this->constrainByRole($qb, 'admin' === $role);
        }

        return new Paginator($qb->getQuery());
    }

    /**
     * The singleton account that authors agent-written content. Inserted by a
     * migration, so its absence is a broken schema rather than a runtime state
     * a caller could recover from.
     */
    public function agent(): User
    {
        return $this->find(Uuid::fromString(User::AGENT_ID))
            ?? throw new \LogicException('The agent user is missing; the database has not been fully migrated.');
    }

    /**
     * DQL cannot express the jsonb membership test, and the admin set is small,
     * so the ids are fetched natively and the builder constrained with them.
     * jsonb_exists() rather than the `?` operator, which DBAL reads as a
     * parameter placeholder.
     */
    private function constrainByRole(QueryBuilder $qb, bool $wantAdmins): void
    {
        $adminIds = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            "SELECT id FROM users WHERE jsonb_exists(roles::jsonb, 'ROLE_ADMIN')",
        );

        if ([] === $adminIds) {
            // No admins: "administrators" matches nothing, "users" matches all.
            if ($wantAdmins) {
                $qb->andWhere('1 = 0');
            }

            return;
        }

        $qb->andWhere($wantAdmins ? 'u.id IN (:adminIds)' : 'u.id NOT IN (:adminIds)')
            ->setParameter('adminIds', $adminIds);
    }
}
