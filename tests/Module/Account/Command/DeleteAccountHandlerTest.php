<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Command\DeleteAccountCommand;
use App\Module\Account\Command\DeleteAccountHandler;
use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\ConnectedAccount;
use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\SocialProvider;
use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Service\StripeGatewayInterface;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\ValueObject\Anchor;
use App\Module\SiteReview\Entity\SiteReview;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DeleteAccountHandlerTest extends KernelTestCase
{
    public function test_invalid_token_throws_and_deletes_nothing(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $user = new User('del-x', 'Del X', 'del-x@example.com', 'hash');
        $em->persist($user);
        $em->flush();
        $userId = $user->id;

        $handler = self::getContainer()->get(DeleteAccountHandler::class);
        self::assertInstanceOf(DeleteAccountHandler::class, $handler);

        try {
            $handler(new DeleteAccountCommand('not-a-token'));
            self::fail('expected DomainErrors');
        } catch (DomainErrors $e) {
            self::assertSame(['token' => 'account.delete.error.invalid_token'], $e->errors);
        }

        $em->clear();
        self::assertNotNull($em->find(User::class, $userId));
    }

    public function test_valid_token_deletes_the_full_owned_graph_and_spares_other_users(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $stripe = $this->createMock(StripeGatewayInterface::class);
        $stripe->expects(self::once())->method('cancelSubscription')->with('sub_delete_me');
        self::getContainer()->set(StripeGatewayInterface::class, $stripe);

        $fixture = $this->seedFullGraph($em);
        $owner = $fixture['owner'];
        $token = $owner->generateAccountDeletionToken();
        $em->flush();
        $ownerId = $owner->id;

        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);
        $archivePath = DataExport::computeArchivePath($projectDir, $fixture['exportId']);
        self::assertFileExists($archivePath);

        $handler = self::getContainer()->get(DeleteAccountHandler::class);
        self::assertInstanceOf(DeleteAccountHandler::class, $handler);
        $handler(new DeleteAccountCommand($token));

        $em->clear();
        self::assertNull($em->find(User::class, $ownerId));
        self::assertFileDoesNotExist($archivePath);

        $conn = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $conn);
        foreach ([
            'users' => 'id',
            'projects' => 'owner_id',
            'documents' => 'owner_id',
            'comments' => 'author_id',
            'reviews' => 'reviewer_id',
            'api_tokens' => 'owner_id',
            'connected_accounts' => 'user_id',
            'data_exports' => 'user_id',
            'billing_profiles' => 'user_id',
        ] as $table => $column) {
            self::assertSame(
                0,
                (int) $conn->fetchOne(sprintf('SELECT count(*) FROM %s WHERE %s = :id', $table, $column), ['id' => (string) $ownerId]),
                sprintf('%s still has rows for the deleted user', $table),
            );
        }

        // The descendant reply, authored by someone else, is gone too — it's
        // a child of the deleted user's own comment.
        self::assertSame(0, (int) $conn->fetchOne('SELECT count(*) FROM comments WHERE id = :id', ['id' => (string) $fixture['replyToOwnerCommentId']]));
        // The grandchild reply survives only because it too was a descendant
        // of the deleted user's comment — confirms the walk goes more than
        // one level deep.
        self::assertSame(0, (int) $conn->fetchOne('SELECT count(*) FROM comments WHERE id = :id', ['id' => (string) $fixture['grandchildReplyId']]));
        // The foreign-owned document (schema-permitted owner != project.owner)
        // and everything under it — including a comment authored by someone
        // else — is gone too.
        self::assertSame(0, (int) $conn->fetchOne('SELECT count(*) FROM documents WHERE id = :id', ['id' => (string) $fixture['foreignDocumentId']]));
        self::assertSame(0, (int) $conn->fetchOne('SELECT count(*) FROM comments WHERE id = :id', ['id' => (string) $fixture['foreignDocumentCommentId']]));

        // Two of the owner's own projects were both fully torn down.
        foreach (['doomedProject1Id', 'doomedProject2Id'] as $key) {
            self::assertNull($em->find(Project::class, $fixture[$key]));
        }
        self::assertSame(0, (int) $conn->fetchOne('SELECT count(*) FROM document_versions WHERE document_id = :id', ['id' => (string) $fixture['foreignDocumentId']]));

        // Nothing belonging to the other, untouched users was removed: their
        // own project, their comment authored on the foreign document, and
        // their account all survive.
        $other = $em->find(User::class, $fixture['otherId']);
        self::assertNotNull($other);
        $spared = $em->find(Project::class, $fixture['otherProjectId']);
        self::assertNotNull($spared);
        self::assertNotNull($em->find(User::class, $fixture['thirdId']));
        self::assertSame(1, (int) $conn->fetchOne('SELECT count(*) FROM comments WHERE id = :id', ['id' => (string) $fixture['otherAuthoredCommentId']]));
    }

    public function test_stripe_failure_is_logged_and_does_not_block_deletion(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $stripe = $this->createStub(StripeGatewayInterface::class);
        $stripe->method('cancelSubscription')->willThrowException(new \RuntimeException('Stripe is down'));
        self::getContainer()->set(StripeGatewayInterface::class, $stripe);

        $owner = new User('del-stripefail', 'Del StripeFail', 'del-stripefail@example.com', 'hash');
        $em->persist($owner);
        $profile = new BillingProfile($owner, new \DateTimeImmutable('+14 days'));
        $profile->stripeCustomerId = 'cus_down';
        $profile->stripeSubscriptionId = 'sub_down';
        $em->persist($profile);
        $token = $owner->generateAccountDeletionToken();
        $em->flush();
        $ownerId = $owner->id;

        $handler = self::getContainer()->get(DeleteAccountHandler::class);
        self::assertInstanceOf(DeleteAccountHandler::class, $handler);
        $handler(new DeleteAccountCommand($token));

        $em->clear();
        self::assertNull($em->find(User::class, $ownerId));
    }

    /**
     * @return array{
     *     owner: User,
     *     otherId: Uuid,
     *     thirdId: Uuid,
     *     exportId: Uuid,
     *     doomedProject1Id: Uuid,
     *     doomedProject2Id: Uuid,
     *     otherProjectId: Uuid,
     *     replyToOwnerCommentId: Uuid,
     *     grandchildReplyId: Uuid,
     *     foreignDocumentId: Uuid,
     *     foreignDocumentCommentId: Uuid,
     *     otherAuthoredCommentId: Uuid,
     * }
     */
    private function seedFullGraph(EntityManagerInterface $em): array
    {
        $owner = $this->makeUser($em, 'deleter-owner');
        $other = $this->makeUser($em, 'deleter-other');
        $third = $this->makeUser($em, 'deleter-third');
        $em->flush();

        // Two of the owner's own projects, each with a full document subtree —
        // exercises ProjectDeleter running twice in the same transaction.
        $doomed1 = $this->seedOwnedProject($em, $owner, 'del-doomed-1');
        $doomed2 = $this->seedOwnedProject($em, $owner, 'del-doomed-2');

        // Another user's project the owner does NOT own, but was invited to
        // review: the owner authors a root comment, the other user replies,
        // and a third user replies to that reply — a two-level descendant
        // chain, by two different authors, hanging off the deleted user's
        // own comment.
        $otherProject = new Project(owner: $other, name: 'del-other-project');
        $em->persist($otherProject);
        $otherDocument = new Document(owner: $other, project: $otherProject, title: 'other doc');
        $em->persist($otherDocument);
        $otherVersion = $otherDocument->addVersion('# Other', '<h1>Other</h1>');

        $ownerComment = new Comment(version: $otherVersion, author: $owner, body: 'owner review comment', anchor: Anchor::unanchored());
        $em->persist($ownerComment);
        $replyToOwner = new Comment(version: $otherVersion, author: $other, body: 'reply by project owner', anchor: Anchor::unanchored(), parent: $ownerComment);
        $em->persist($replyToOwner);
        $grandchildReply = new Comment(version: $otherVersion, author: $third, body: 'grandchild reply', anchor: Anchor::unanchored(), parent: $replyToOwner);
        $em->persist($grandchildReply);
        $otherAuthoredComment = new Comment(version: $otherVersion, author: $other, body: 'unrelated comment by project owner', anchor: Anchor::unanchored());
        $em->persist($otherAuthoredComment);

        $em->persist(new Review(version: $otherVersion, verdict: Verdict::Approved, reviewer: $owner));

        // A document the deleted user owns inside someone ELSE's project — the
        // schema permits document.owner != project.owner even though no
        // production code path creates it today.
        $foreignDocument = new Document(owner: $owner, project: $otherProject, title: 'owner doc in other project');
        $em->persist($foreignDocument);
        $foreignVersion = $foreignDocument->addVersion('# Foreign', '<h1>Foreign</h1>');
        $foreignDocumentComment = new Comment(version: $foreignVersion, author: $other, body: 'other user comments on it', anchor: Anchor::unanchored());
        $em->persist($foreignDocumentComment);

        // A loose API token not bound to any project's widget/mcp slots.
        [$looseToken] = ApiToken::issue($owner, 'loose-token', ApiTokenScope::Mcp);
        $em->persist($looseToken);

        $connectedAccount = new ConnectedAccount($owner, SocialProvider::Github, 'gh-'.uniqid(), 'owner@github.example');
        $em->persist($connectedAccount);

        $export = new DataExport($owner);
        $em->persist($export);

        $profile = new BillingProfile($owner, new \DateTimeImmutable('+14 days'));
        $profile->stripeCustomerId = 'cus_delete_me';
        $profile->stripeSubscriptionId = 'sub_delete_me';
        $em->persist($profile);

        $em->flush();

        $exportId = $export->id ?? throw new \LogicException('flushed export always has an id');
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        \assert(is_string($projectDir));
        $archivePath = DataExport::computeArchivePath($projectDir, $exportId);
        if (!is_dir(\dirname($archivePath))) {
            mkdir(\dirname($archivePath), 0o777, true);
        }
        file_put_contents($archivePath, 'fixture archive contents');

        return [
            'owner' => $owner,
            'otherId' => $other->id ?? throw new \LogicException('flushed user always has an id'),
            'thirdId' => $third->id ?? throw new \LogicException('flushed user always has an id'),
            'exportId' => $exportId,
            'doomedProject1Id' => $doomed1->id ?? throw new \LogicException('flushed project always has an id'),
            'doomedProject2Id' => $doomed2->id ?? throw new \LogicException('flushed project always has an id'),
            'otherProjectId' => $otherProject->id ?? throw new \LogicException('flushed project always has an id'),
            'replyToOwnerCommentId' => $replyToOwner->id ?? throw new \LogicException('flushed comment always has an id'),
            'grandchildReplyId' => $grandchildReply->id ?? throw new \LogicException('flushed comment always has an id'),
            'foreignDocumentId' => $foreignDocument->id ?? throw new \LogicException('flushed document always has an id'),
            'foreignDocumentCommentId' => $foreignDocumentComment->id ?? throw new \LogicException('flushed comment always has an id'),
            'otherAuthoredCommentId' => $otherAuthoredComment->id ?? throw new \LogicException('flushed comment always has an id'),
        ];
    }

    private function makeUser(EntityManagerInterface $em, string $slug): User
    {
        $user = new User(username: $slug, fullName: 'Deleter Test', email: $slug.'@example.test', password: 'irrelevant-hash');
        $em->persist($user);

        return $user;
    }

    private function seedOwnedProject(EntityManagerInterface $em, User $owner, string $slug): Project
    {
        $project = new Project(owner: $owner, name: $slug);
        $em->persist($project);

        $document = new Document(owner: $owner, project: $project, title: $slug.' doc');
        $em->persist($document);
        $version = $document->addVersion('# Hi', '<h1>Hi</h1>');
        $parent = new Comment(version: $version, author: $owner, body: 'root', anchor: Anchor::unanchored());
        $em->persist($parent);
        $reply = new Comment(version: $version, author: $owner, body: 'reply', anchor: Anchor::unanchored(), parent: $parent);
        $em->persist($reply);
        $em->persist(new Review(version: $version, verdict: Verdict::Approved, reviewer: $owner));

        $siteReview = new SiteReview(project: $project);
        $em->persist($siteReview);
        $siteReview->addComment('widget comment', 'body', 'x', 'https://example.test/');

        [$widgetToken] = ApiToken::issue($owner, $slug.'-widget', ApiTokenScope::SiteReview);
        [$mcpToken] = ApiToken::issue($owner, $slug.'-mcp', ApiTokenScope::Mcp);
        $project->widgetToken = $widgetToken;
        $project->mcpToken = $mcpToken;
        $em->persist($widgetToken);
        $em->persist($mcpToken);

        return $project;
    }
}
