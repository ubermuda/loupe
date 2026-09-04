<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\ReplyToCommentCommand;
use App\Module\Review\Command\ReplyToCommentHandler;
use App\Module\Review\Command\ResolveCommentCommand;
use App\Module\Review\Command\ResolveCommentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\ValueObject\Anchor;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final class ReviseDocumentHandlerTest extends KernelTestCase
{
    public function test_revise_adds_version_reanchors_comments_and_sets_status_in_review(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User(fullName: 'Agent', email: 'agent@example.com', password: 'hashed');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        // Create document with first version containing "use JWTs and rate limiting".
        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Auth PRD', 'use JWTs and rate limiting'));

        $v1 = $doc->currentVersion();

        // Add an open comment on v1 — quote "JWTs" survives into the new version.
        $survivingComment = new Comment(
            $v1,
            $user,
            'why JWT?',
            new Anchor('JWTs', 'use ', ' and', 4),
        );
        // Add an open comment on v1 — quote "rate limiting" will be gone in new version.
        $orphanedComment = new Comment(
            $v1,
            $user,
            'limit?',
            new Anchor('rate limiting', 'and ', '', 13),
        );
        $em->persist($survivingComment);
        $em->persist($orphanedComment);
        $em->flush();

        $docId = $doc->id;
        self::assertInstanceOf(Uuid::class, $docId);

        // Revise with new markdown that keeps "JWTs" but removes "rate limiting".
        /** @var ReviseDocumentHandler $reviseHandler */
        $reviseHandler = self::getContainer()->get(ReviseDocumentHandler::class);
        $summary = $reviseHandler(new ReviseDocumentCommand($doc, 'use JWTs only', 'Dropped rate limiting.'));

        self::assertSame(1, $summary['carried']);
        self::assertSame(1, $summary['orphaned']);

        // Clear and re-fetch to avoid stale in-memory state.
        $em->clear();
        $freshDoc = $em->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $freshDoc);

        self::assertSame(2, $freshDoc->versions->count());
        self::assertSame(DocumentStatus::InReview, $freshDoc->status);

        // The new version should have 2 copied comments (one carried, one orphaned).
        /** @var CommentRepository $commentRepository */
        $commentRepository = self::getContainer()->get(CommentRepository::class);
        $v2Comments = $commentRepository->findByVersion($freshDoc->currentVersion());

        self::assertCount(2, $v2Comments);

        $carriedCopies = array_filter($v2Comments, fn (Comment $c) => !$c->orphaned);
        $orphanedCopies = array_filter($v2Comments, fn (Comment $c) => $c->orphaned);

        self::assertCount(1, $carriedCopies);
        self::assertCount(1, $orphanedCopies);

        $carried = reset($carriedCopies);
        self::assertInstanceOf(Comment::class, $carried);
        self::assertFalse($carried->orphaned);
        self::assertSame('why JWT?', $carried->body);
        self::assertSame('JWTs', $carried->anchor->quote);

        $orphaned = reset($orphanedCopies);
        self::assertInstanceOf(Comment::class, $orphaned);
        self::assertTrue($orphaned->orphaned);
    }

    /**
     * Regression for a resolved thread whose reply used to resurrect: findOpenByVersion() selected
     * on a per-row resolved flag with no parent check, so a reply of a resolved root was copied onto
     * the new version with its parent detached (the resolved root isn't in the open set), reappearing
     * as a brand-new open top-level thread on every subsequent revision.
     */
    public function test_resolved_thread_carries_nothing_forward_even_with_an_unresolved_reply(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User(fullName: 'Agent', email: 'agent2@example.com', password: 'hashed');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Resolved Thread Doc', 'use JWTs and rate limiting'));

        $v1 = $doc->currentVersion();

        $root = new Comment($v1, $user, 'why JWT?', new Anchor('JWTs', 'use ', ' and', 4));
        $em->persist($root);
        $em->flush();

        /** @var ReplyToCommentHandler $replyHandler */
        $replyHandler = self::getContainer()->get(ReplyToCommentHandler::class);
        $replyHandler(new ReplyToCommentCommand(actor: $user, parent: $root, body: 'Still an open question'));

        /** @var ResolveCommentHandler $resolveHandler */
        $resolveHandler = self::getContainer()->get(ResolveCommentHandler::class);
        $resolveHandler(new ResolveCommentCommand(comment: $root));

        $docId = $doc->id;
        self::assertInstanceOf(Uuid::class, $docId);

        /** @var ReviseDocumentHandler $reviseHandler */
        $reviseHandler = self::getContainer()->get(ReviseDocumentHandler::class);
        $summary = $reviseHandler(new ReviseDocumentCommand($doc, 'use JWTs only', 'Dropped rate limiting.'));

        self::assertSame(0, $summary['carried'], 'a resolved thread (root or reply) must carry nothing forward');
        self::assertSame(0, $summary['orphaned']);

        $em->clear();
        $freshDoc = $em->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $freshDoc);

        /** @var CommentRepository $commentRepository */
        $commentRepository = self::getContainer()->get(CommentRepository::class);
        $v2Comments = $commentRepository->findByVersion($freshDoc->currentVersion());

        self::assertCount(0, $v2Comments, 'nothing from the resolved thread should appear on the new version');
    }

    /**
     * An addressed thread is still open: the agent claiming it acted is not the human agreeing the
     * thread is finished, so it must survive a revision — with its status, and with its replies
     * still attached to it rather than detached into threads of their own.
     */
    public function test_addressed_thread_carries_forward_with_its_status_and_its_reply(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User(fullName: 'Agent', email: 'agent4@example.com', password: 'hashed');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Addressed Thread Doc', 'use JWTs and rate limiting'));

        $v1 = $doc->currentVersion();

        $root = new Comment($v1, $user, 'why JWT?', new Anchor('JWTs', 'use ', ' and', 4));
        $root->status = CommentStatus::Addressed;
        $em->persist($root);
        $em->flush();

        /** @var ReplyToCommentHandler $replyHandler */
        $replyHandler = self::getContainer()->get(ReplyToCommentHandler::class);
        $replyHandler(new ReplyToCommentCommand(actor: $user, parent: $root, body: 'Rewritten that section'));

        $docId = $doc->id;
        self::assertInstanceOf(Uuid::class, $docId);

        /** @var ReviseDocumentHandler $reviseHandler */
        $reviseHandler = self::getContainer()->get(ReviseDocumentHandler::class);
        $summary = $reviseHandler(new ReviseDocumentCommand($doc, 'use JWTs only', 'Narrowed the token guidance to JWTs.'));

        self::assertSame(2, $summary['carried'], 'an addressed thread carries its root and its reply forward');
        self::assertSame(0, $summary['orphaned']);

        $em->clear();
        $freshDoc = $em->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $freshDoc);

        /** @var CommentRepository $commentRepository */
        $commentRepository = self::getContainer()->get(CommentRepository::class);
        $v2Comments = $commentRepository->findByVersion($freshDoc->currentVersion());

        self::assertCount(2, $v2Comments);

        $roots = array_values(array_filter($v2Comments, static fn (Comment $c): bool => null === $c->parent));
        $replies = array_values(array_filter($v2Comments, static fn (Comment $c): bool => null !== $c->parent));

        self::assertCount(1, $roots, 'the reply must stay attached, not become a thread of its own');
        self::assertCount(1, $replies);
        self::assertSame(CommentStatus::Addressed, $roots[0]->status, 'the agent claim survives the revision');
        self::assertSame($roots[0], $replies[0]->parent);
        self::assertSame(CommentStatus::Addressed, $replies[0]->threadStatus);
    }

    /**
     * A carried comment is the same comment on a new version, not a new one, so
     * its age keeps counting from when it was written. Without this the copy takes
     * the entity default and every thread reads as freshly posted after a revision.
     */
    public function test_a_carried_comment_and_its_reply_keep_their_original_created_at(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User(fullName: 'Agent', email: 'agent-createdat@example.com', password: 'hashed');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Carried Age Doc', 'use JWTs and rate limiting'));

        $v1 = $doc->currentVersion();
        $writtenAt = new \DateTimeImmutable('-3 days');
        $repliedAt = new \DateTimeImmutable('-2 days');

        $root = new Comment($v1, $user, 'why JWT?', new Anchor('JWTs', 'use ', ' and', 4), createdAt: $writtenAt);
        $em->persist($root);
        $reply = new Comment($v1, $user, 'Because sessions do not travel', new Anchor('JWTs', 'use ', ' and', 4), parent: $root, createdAt: $repliedAt);
        $em->persist($reply);
        $em->flush();

        $docId = $doc->id;
        self::assertInstanceOf(Uuid::class, $docId);

        /** @var ReviseDocumentHandler $reviseHandler */
        $reviseHandler = self::getContainer()->get(ReviseDocumentHandler::class);
        $summary = $reviseHandler(new ReviseDocumentCommand($doc, 'use JWTs only', 'Narrowed the token guidance to JWTs.'));

        self::assertSame(2, $summary['carried']);

        $em->clear();
        $freshDoc = $em->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $freshDoc);

        /** @var CommentRepository $commentRepository */
        $commentRepository = self::getContainer()->get(CommentRepository::class);
        $v2Comments = $commentRepository->findByVersion($freshDoc->currentVersion());
        self::assertCount(2, $v2Comments);

        $carriedRoot = array_first(array_filter($v2Comments, static fn (Comment $c): bool => null === $c->parent));
        $carriedReply = array_first(array_filter($v2Comments, static fn (Comment $c): bool => null !== $c->parent));
        self::assertInstanceOf(Comment::class, $carriedRoot);
        self::assertInstanceOf(Comment::class, $carriedReply);

        self::assertNotNull($carriedRoot->createdAt);
        self::assertNotNull($carriedReply->createdAt);
        self::assertSame($writtenAt->format('Y-m-d H:i:s'), $carriedRoot->createdAt->format('Y-m-d H:i:s'));
        self::assertSame($repliedAt->format('Y-m-d H:i:s'), $carriedReply->createdAt->format('Y-m-d H:i:s'));
    }

    /**
     * Regression guard for concurrent revisions computing the same "next
     * version number": two sequential revisions must land as versionNumber
     * 2 and 3, not both landing on 2 (the fixed bug) or the second one 500ing
     * on the unique constraint. This does not exercise true concurrency —
     * dama/doctrine-test-bundle wraps the whole test in one connection's
     * transaction, so two overlapping DB transactions cannot be expressed
     * here; the lock ordering itself is verified by code review.
     */
    public function test_revising_does_not_load_the_documents_versions(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User(fullName: 'Agent', email: 'lazy-'.uniqid().'@example.com', password: 'hashed');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Lazy', 'first'));
        $id = $doc->id;
        self::assertNotNull($id);

        // A document loaded fresh, exactly as a request would have it: the
        // versions collection is a proxy nobody has touched yet.
        $em->clear();
        $doc = $em->find(Document::class, $id);
        self::assertNotNull($doc);
        $versions = $doc->versions;
        self::assertInstanceOf(PersistentCollection::class, $versions);
        self::assertFalse($versions->isInitialized(), 'precondition: collection starts uninitialised');

        $reviseHandler = self::getContainer()->get(ReviseDocumentHandler::class);
        $reviseHandler(new ReviseDocumentCommand($doc, 'second', 'a revision'));

        // The whole point: numbering the new version used to count the
        // collection, which loads every version of the document.
        self::assertFalse($versions->isInitialized());
    }

    public function test_two_sequential_revisions_get_consecutive_version_numbers(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User(fullName: 'Agent', email: 'agent3@example.com', password: 'hashed');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Sequential Revisions', 'v1 content'));

        $docId = $doc->id;
        self::assertInstanceOf(Uuid::class, $docId);

        /** @var ReviseDocumentHandler $reviseHandler */
        $reviseHandler = self::getContainer()->get(ReviseDocumentHandler::class);
        $reviseHandler(new ReviseDocumentCommand($doc, 'v2 content', 'Second pass.'));
        $reviseHandler(new ReviseDocumentCommand($doc, 'v3 content', 'Third pass.'));

        $em->clear();
        $freshDoc = $em->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $freshDoc);

        $versionNumbers = array_map(
            static fn (DocumentVersion $version): int => $version->versionNumber,
            $freshDoc->versions->toArray(),
        );

        self::assertSame([1, 2, 3], $versionNumbers);
    }

    public function test_revise_stores_the_description_on_the_new_version_only(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User(fullName: 'Agent', email: 'agent4@example.com', password: 'hashed');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand(project: $project, title: 'Described Doc', markdown: 'v1 content', description: 'The original brief.'));

        $docId = $doc->id;
        self::assertInstanceOf(Uuid::class, $docId);

        /** @var ReviseDocumentHandler $reviseHandler */
        $reviseHandler = self::getContainer()->get(ReviseDocumentHandler::class);
        $reviseHandler(new ReviseDocumentCommand($doc, 'v2 content', 'Replaced the rollout section with a phased plan.'));

        $em->clear();
        $freshDoc = $em->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $freshDoc);

        $descriptions = array_map(
            static fn (DocumentVersion $version): ?string => $version->description,
            $freshDoc->versions->toArray(),
        );

        self::assertSame(
            ['The original brief.', 'Replaced the rollout section with a phased plan.'],
            $descriptions,
        );
    }

    public function test_revise_updates_the_title_when_one_is_given_and_keeps_it_otherwise(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User(fullName: 'Agent', email: 'agent5@example.com', password: 'hashed');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Eight features', 'v1 content'));

        $docId = $doc->id;
        self::assertInstanceOf(Uuid::class, $docId);

        /** @var ReviseDocumentHandler $reviseHandler */
        $reviseHandler = self::getContainer()->get(ReviseDocumentHandler::class);
        $reviseHandler(new ReviseDocumentCommand($doc, 'v2 content', 'Added a ninth feature.', 'Nine features'));

        $em->clear();
        $renamed = $em->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $renamed);
        self::assertSame('Nine features', $renamed->title);

        $reviseHandler(new ReviseDocumentCommand($renamed, 'v3 content', 'Tightened the wording.'));

        $em->clear();
        $unchanged = $em->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $unchanged);
        self::assertSame('Nine features', $unchanged->title);
    }

    /**
     * The guard is the handler's, not any one caller's: an over-long title
     * reaching the column would abort inside the transaction and discard an
     * otherwise-correct revision — new version, re-anchored comments and all —
     * as a database error rather than a field error.
     */
    public function test_an_over_long_title_is_rejected_without_adding_a_version(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User(fullName: 'Agent', email: 'agent6@example.com', password: 'hashed');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Keep me', 'v1 content'));

        $docId = $doc->id;
        self::assertInstanceOf(Uuid::class, $docId);

        /** @var ReviseDocumentHandler $reviseHandler */
        $reviseHandler = self::getContainer()->get(ReviseDocumentHandler::class);

        try {
            $reviseHandler(new ReviseDocumentCommand(
                $doc,
                'v2 content',
                'Would have been fine.',
                str_repeat('a', Document::MAX_TITLE_LENGTH + 1),
            ));
            self::fail('an over-long title must be rejected');
        } catch (DomainErrors $e) {
            self::assertSame(['title' => 'review.rename.error.too_long'], $e->errors);
        }

        $em->clear();
        $fresh = $em->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $fresh);
        self::assertSame('Keep me', $fresh->title);
        self::assertCount(1, $fresh->versions);
    }

    public function test_a_blank_title_is_rejected(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User(fullName: 'Agent', email: 'agent7@example.com', password: 'hashed');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Keep me too', 'v1 content'));

        /** @var ReviseDocumentHandler $reviseHandler */
        $reviseHandler = self::getContainer()->get(ReviseDocumentHandler::class);

        try {
            $reviseHandler(new ReviseDocumentCommand($doc, 'v2 content', 'Fine.', '   '));
            self::fail('a blank title must be rejected');
        } catch (DomainErrors $e) {
            self::assertSame(['title' => 'review.rename.error.blank'], $e->errors);
        }

        self::assertSame('Keep me too', $doc->title);
    }

    public function test_a_blank_description_is_rejected_without_adding_a_version(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User(fullName: 'Agent', email: 'agent8@example.com', password: 'hashed');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Undescribed', 'v1 content'));

        $docId = $doc->id;
        self::assertInstanceOf(Uuid::class, $docId);

        /** @var ReviseDocumentHandler $reviseHandler */
        $reviseHandler = self::getContainer()->get(ReviseDocumentHandler::class);

        try {
            $reviseHandler(new ReviseDocumentCommand($doc, 'v2 content', '   '));
            self::fail('a blank description must be rejected');
        } catch (DomainErrors $e) {
            self::assertSame(['description' => 'review.revise.error.description_blank'], $e->errors);
        }

        $em->clear();
        $fresh = $em->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $fresh);
        self::assertCount(1, $fresh->versions);
    }

    public function test_the_stored_description_and_title_are_trimmed(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User(fullName: 'Agent', email: 'agent9@example.com', password: 'hashed');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Untrimmed', 'v1 content'));

        $docId = $doc->id;
        self::assertInstanceOf(Uuid::class, $docId);

        /** @var ReviseDocumentHandler $reviseHandler */
        $reviseHandler = self::getContainer()->get(ReviseDocumentHandler::class);
        $reviseHandler(new ReviseDocumentCommand($doc, 'v2 content', '  Trimmed me.  ', '  Trimmed title  '));

        $em->clear();
        $fresh = $em->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $fresh);
        self::assertSame('Trimmed title', $fresh->title);
        self::assertSame('Trimmed me.', $fresh->currentVersion()->description);
    }

    public function test_a_revision_is_recorded_on_the_domain_channel(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $user = new User(fullName: 'Agent', email: 'revise-audit@example.com', password: 'hashed');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        // Installed before the fixture, because the container refuses to
        // replace a service it has already built, and creating the document
        // builds the Auditor.
        $audit = RecordingAuditor::installedIn(self::getContainer());

        $create = self::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $create);
        $document = $create(new CreateDocumentCommand($project, 'Auth PRD', 'use JWTs and rate limiting'));

        $comment = new Comment($document->currentVersion(), $user, 'why?', new Anchor('JWTs', 'use ', ' and', 4));
        $em->persist($comment);
        $em->flush();
        $audit->forget();

        $revise = self::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);

        $revise(new ReviseDocumentCommand($document, 'use JWTs everywhere', 'tightened', 'Auth PRD v2'));

        $record = $audit->record('review.document_revised');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('document', $record->subject->type);
        self::assertSame((string) $document->id, $record->subject->id);
        self::assertSame([
            'documentId' => (string) $document->id,
            'projectId' => (string) $project->id,
            'versionNumber' => 2,
            'titleChanged' => true,
            'referencesReplaced' => false,
            'commentsCarried' => 1,
            'commentsOrphaned' => 0,
            'sectionsCarried' => 0,
            'sectionsDropped' => 0,
        ], $record->context);

        self::assertSame(['review.document_revised'], $audit->domainLogLines());
        self::assertSame([], $audit->securityLogLines());
    }

    /** Neither the new title nor the Markdown reaches the trail. */
    public function test_the_revision_record_carries_no_document_text(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $user = new User(fullName: 'Agent', email: 'revise-audit-text@example.com', password: 'hashed');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        $audit = RecordingAuditor::installedIn(self::getContainer());

        $create = self::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $create);
        $document = $create(new CreateDocumentCommand($project, 'Plan', 'first draft'));
        $audit->forget();

        $revise = self::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);

        $revise(new ReviseDocumentCommand($document, 'Dana disagreed', 'Dana asked for this', 'Plan about Dana'));

        self::assertSame([], array_filter(
            $audit->record('review.document_revised')->context,
            static fn (string|int|float|bool|null $value): bool => \is_string($value) && str_contains($value, 'Dana'),
        ));
    }

    public function test_a_rejected_revision_records_nothing(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $user = new User(fullName: 'Agent', email: 'revise-audit-refused@example.com', password: 'hashed');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        $audit = RecordingAuditor::installedIn(self::getContainer());

        $create = self::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $create);
        $document = $create(new CreateDocumentCommand($project, 'Plan', 'first draft'));
        $audit->forget();

        $revise = self::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);

        try {
            $revise(new ReviseDocumentCommand($document, 'v2', '   '));
            self::fail('a blank description must be rejected');
        } catch (DomainErrors) {
        }

        self::assertSame([], $audit->operations());
    }
}
