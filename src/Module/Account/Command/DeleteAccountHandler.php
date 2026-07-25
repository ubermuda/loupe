<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\StripeGatewayInterface;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\ProjectDeleter;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Validates the deletion token, cancels any Stripe subscription, and
 * hard-deletes the user and every row they own, in FK-safe order, inside one
 * transaction.
 *
 * The eight tables with a foreign key onto `users` (confirmed against the
 * schema, not just the entity map) are: projects, documents, comments,
 * reviews, api_tokens, data_exports, billing_profiles, connected_accounts.
 * Every one of them is cleared below before the user row itself is deleted.
 */
final readonly class DeleteAccountHandler
{
    public function __construct(
        private UserRepository $users,
        private ProjectRepository $projects,
        private ProjectDeleter $projectDeleter,
        private BillingProfileRepository $billingProfiles,
        private StripeGatewayInterface $stripeGateway,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,

        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    public function __invoke(DeleteAccountCommand $command): void
    {
        $user = $this->users->findByAccountDeletionToken($command->token);
        if (!$user instanceof User || !$user->isAccountDeletionTokenValid($command->token)) {
            throw new DomainErrors(['token' => 'account.delete.error.invalid_token']);
        }

        // Outside the transaction: an external API call must not hold a DB
        // transaction open, and its failure must never block the deletion. On
        // failure this error log is the durable reconciliation record — the
        // customer remains findable in the Stripe dashboard by email, and
        // support cancels manually. Stripe downtime is a bad minute, not a
        // reason to trap a user who wants to leave.
        $profile = $this->billingProfiles->findOneByUser($user);
        if (null !== $profile && null !== $profile->stripeSubscriptionId) {
            try {
                $this->stripeGateway->cancelSubscription($profile->stripeSubscriptionId);
            } catch (\Throwable $e) {
                $this->logger->error('account.deletion.stripe_cancel_failed', [
                    'userId' => (string) $user->id,
                    'stripeCustomerId' => $profile->stripeCustomerId,
                    'stripeSubscriptionId' => $profile->stripeSubscriptionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $userId = $user->id ?? throw new \LogicException('a persisted user always has an id');
        $archivePaths = [];

        $this->em->wrapInTransaction(function () use ($user, $userId, &$archivePaths): void {
            $conn = $this->em->getConnection();
            $id = (string) $userId;

            // Every project the user owns, via F4's ProjectDeleter — it already
            // handles the full project subtree (documents, versions, comments,
            // reviews, site reviews, and the project's own two bound API
            // tokens) inside its own nested transaction. Resolve ids up front,
            // then re-fetch and clear() around each delete: ProjectDeleter's
            // listeners remove SiteReview/SiteReviewComment rows via bulk DQL,
            // which bypasses the identity map, so an earlier project's
            // now-stale SiteReview object survives into the next flush() and
            // Doctrine misreports its already-deleted `project` as a new,
            // non-cascaded entity. Clearing after each delete avoids that.
            $projectIds = array_map(static fn (Project $p): Uuid => $p->id ?? throw new \LogicException('a persisted project always has an id'), $this->projects->findBy(['owner' => $user]));
            foreach ($projectIds as $projectId) {
                $project = $this->projects->find($projectId);
                if (null !== $project) {
                    $this->projectDeleter->delete($project);
                    $this->em->clear();
                }
            }

            // Data export archive files live on disk under var/exports/; collect
            // their paths before removing the rows so they can be unlinked only
            // after a successful commit (a rollback must not have already
            // destroyed files a still-existing row points at).
            $exportIds = array_map(strval(...), $conn->fetchFirstColumn('SELECT id FROM data_exports WHERE user_id = :id', ['id' => $id]));
            foreach ($exportIds as $exportId) {
                $archivePaths[] = DataExport::computeArchivePath($this->projectDir, Uuid::fromString($exportId));
            }
            $conn->executeStatement('DELETE FROM data_exports WHERE user_id = :id', ['id' => $id]);

            // Documents the user owns inside OTHER owners' projects. The schema
            // permits document.owner to differ from project.owner even though no
            // code path creates that today — documents.owner_id is NOT NULL, so
            // a latent such document would otherwise block the user delete.
            // Same FK-safe order as F4's DeleteReviewDataOnProjectDeleting
            // (reviews, comments, versions, then the document), keyed on
            // document ownership instead of the project.
            $conn->executeStatement(
                'DELETE FROM reviews WHERE version_id IN (SELECT v.id FROM document_versions v JOIN documents d ON v.document_id = d.id WHERE d.owner_id = :id)',
                ['id' => $id],
            );
            $conn->executeStatement(
                'DELETE FROM comments WHERE version_id IN (SELECT v.id FROM document_versions v JOIN documents d ON v.document_id = d.id WHERE d.owner_id = :id)',
                ['id' => $id],
            );
            $conn->executeStatement(
                'DELETE FROM document_versions WHERE document_id IN (SELECT id FROM documents WHERE owner_id = :id)',
                ['id' => $id],
            );
            $conn->executeStatement('DELETE FROM documents WHERE owner_id = :id', ['id' => $id]);

            // Every API token the user owns. ProjectDeleter above only clears
            // the two tokens reachable from a project's widget/mcp bindings —
            // api_tokens.owner_id is NOT NULL and needs its own sweep.
            $conn->executeStatement('DELETE FROM api_tokens WHERE owner_id = :id', ['id' => $id]);

            // Comments the user authored on documents they do NOT own — e.g. as
            // an invited reviewer on someone else's project. comments.parent_id
            // has no ON DELETE clause, so any live descendant reply (by any
            // author) must be removed first. Walk downward from the user's own
            // comments, collecting the full descendant closure level by level,
            // then delete deepest level first so a row is never removed while
            // something still points at it as a parent.
            $levels = [];
            $frontier = array_map(strval(...), $conn->fetchFirstColumn('SELECT id FROM comments WHERE author_id = :id', ['id' => $id]));
            while ([] !== $frontier) {
                $levels[] = $frontier;
                $frontier = array_map(strval(...), $conn->fetchFirstColumn(
                    'SELECT id FROM comments WHERE parent_id IN (:ids)',
                    ['ids' => $frontier],
                    ['ids' => ArrayParameterType::STRING],
                ));
            }
            foreach (array_reverse($levels) as $level) {
                $conn->executeStatement(
                    'DELETE FROM comments WHERE id IN (:ids)',
                    ['ids' => $level],
                    ['ids' => ArrayParameterType::STRING],
                );
            }

            // Reviews the user submitted on documents they do not own.
            $conn->executeStatement('DELETE FROM reviews WHERE reviewer_id = :id', ['id' => $id]);

            $conn->executeStatement('DELETE FROM connected_accounts WHERE user_id = :id', ['id' => $id]);
            $conn->executeStatement('DELETE FROM billing_profiles WHERE user_id = :id', ['id' => $id]);

            $conn->executeStatement('DELETE FROM users WHERE id = :id', ['id' => $id]);
        });

        // Only after a successful commit is it safe to destroy files: a
        // rolled-back transaction must leave the still-existing rows' archives
        // in place.
        foreach ($archivePaths as $path) {
            if (is_file($path) && !@unlink($path)) {
                $this->logger->warning('account.deletion.archive_unlink_failed', ['path' => $path]);
            }
        }

        $this->logger->info('account.deleted', ['userId' => (string) $userId]);
    }
}
