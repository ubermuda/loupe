<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Service;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Event\ProjectDeleting;
use App\Module\Project\Service\ProjectDeleter;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\DecisionSelection;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Highlight;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Tag;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\ValueObject\Anchor;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProjectDeleterTest extends KernelTestCase
{
    public function test_delete_removes_the_full_object_graph_and_spares_siblings(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $owner = $this->makeUser($em, 'deleter-owner');
        $doomed = $this->seedFullProject($em, $owner, 'doomed');
        $spared = $this->seedFullProject($em, $owner, 'spared');
        $em->flush();
        $doomedId = $doomed->id;
        $sparedId = $spared->id;
        $doomedWidgetTokenId = $doomed->widgetToken->id ?? throw new \LogicException('widget token seeded');
        $doomedMcpTokenId = $doomed->mcpToken->id ?? throw new \LogicException('mcp token seeded');
        $em->clear();

        // Captured before the delete: once the documents are gone, a join-table
        // count reached through them can no longer fail, so the id is what makes
        // the assertions below able to catch a surviving join row.
        $conn = $em->getConnection();
        $doomedReferenceSourceId = (string) $conn->fetchOne(
            'SELECT r.source_document_id FROM document_references r JOIN documents d ON r.source_document_id = d.id WHERE d.project_id = :id',
            ['id' => (string) $doomedId],
        );
        self::assertNotSame('', $doomedReferenceSourceId);
        // Resolved through document_tags rather than by picking any document of
        // the project, so it stays the tagged one however many the fixture seeds.
        $doomedDocumentId = (string) $conn->fetchOne(
            'SELECT dt.document_id FROM document_tags dt JOIN documents d ON dt.document_id = d.id WHERE d.project_id = :id',
            ['id' => (string) $doomedId],
        );
        $sparedDocumentId = (string) $conn->fetchOne(
            'SELECT dt.document_id FROM document_tags dt JOIN documents d ON dt.document_id = d.id WHERE d.project_id = :id',
            ['id' => (string) $sparedId],
        );
        self::assertNotSame('', $doomedDocumentId);
        self::assertNotSame('', $sparedDocumentId);

        $doomed = $em->find(Project::class, $doomedId);
        self::assertNotNull($doomed);
        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($doomed);
        $em->clear();

        self::assertNull($em->find(Project::class, $doomedId));
        self::assertSame(0, (int) $conn->fetchOne('SELECT count(*) FROM document_tags WHERE document_id = :id', ['id' => $doomedDocumentId]));
        self::assertSame(0, (int) $conn->fetchOne(
            'SELECT count(*) FROM document_references WHERE source_document_id = :id',
            ['id' => $doomedReferenceSourceId],
        ));
        foreach ([
            'tags' => 'SELECT count(*) FROM tags WHERE project_id = :id',
            'site_review_events' => 'SELECT count(*) FROM site_review_events WHERE project_id = :id',
            'site_review_comments' => 'SELECT count(*) FROM site_review_comments WHERE project_id = :id',
            'comments' => 'SELECT count(*) FROM comments c JOIN document_versions v ON c.version_id = v.id JOIN documents d ON v.document_id = d.id WHERE d.project_id = :id',
            'reviews' => 'SELECT count(*) FROM reviews rv JOIN document_versions v ON rv.version_id = v.id JOIN documents d ON v.document_id = d.id WHERE d.project_id = :id',
            'document_highlights' => 'SELECT count(*) FROM document_highlights h JOIN document_versions v ON h.version_id = v.id JOIN documents d ON v.document_id = d.id WHERE d.project_id = :id',
            'decision_selections' => 'SELECT count(*) FROM decision_selections s JOIN documents d ON s.document_id = d.id WHERE d.project_id = :id',
            'document_versions' => 'SELECT count(*) FROM document_versions v JOIN documents d ON v.document_id = d.id WHERE d.project_id = :id',
            'documents' => 'SELECT count(*) FROM documents WHERE project_id = :id',
        ] as $table => $sql) {
            self::assertSame(0, (int) $conn->fetchOne($sql, ['id' => (string) $doomedId]), sprintf('orphans left in %s', $table));
        }
        // Both bound tokens are gone — assert by the captured token IDs, not by
        // name pattern (prevents false passes from unrelated rows).
        foreach ([$doomedWidgetTokenId, $doomedMcpTokenId] as $tokenId) {
            self::assertSame(0, (int) $conn->fetchOne('SELECT count(*) FROM api_tokens WHERE id = :id', ['id' => (string) $tokenId]));
        }

        // The sibling project and its whole graph survive.
        $spared = $em->find(Project::class, $sparedId);
        self::assertNotNull($spared);
        self::assertSame(2, (int) $conn->fetchOne('SELECT count(*) FROM documents WHERE project_id = :id', ['id' => (string) $sparedId]));
        self::assertSame(1, (int) $conn->fetchOne('SELECT count(*) FROM document_references r JOIN documents d ON r.source_document_id = d.id WHERE d.project_id = :id', ['id' => (string) $sparedId]));
        self::assertSame(1, (int) $conn->fetchOne('SELECT count(*) FROM site_review_comments WHERE project_id = :id', ['id' => (string) $sparedId]));
        self::assertSame(1, (int) $conn->fetchOne('SELECT count(*) FROM site_review_events WHERE project_id = :id', ['id' => (string) $sparedId]));
        self::assertSame(1, (int) $conn->fetchOne('SELECT count(*) FROM tags WHERE project_id = :id', ['id' => (string) $sparedId]));
        self::assertSame(1, (int) $conn->fetchOne('SELECT count(*) FROM document_tags WHERE document_id = :id', ['id' => $sparedDocumentId]));
        self::assertNotNull($spared->widgetToken);
        self::assertNotNull($spared->mcpToken);
    }

    public function test_a_failing_listener_rolls_the_whole_deletion_back(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = $this->makeUser($em, 'rollback-owner');
        $project = $this->seedFullProject($em, $owner, 'rollback');
        $em->flush();
        $projectId = $project->id;
        $em->clear();

        // A listener that fires after the module listeners and blows up: every
        // bulk delete already executed must roll back with the transaction.
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $dispatcher->addListener(ProjectDeleting::class, static function (): void {
            throw new \RuntimeException('boom');
        }, -100);

        $project = $em->find(Project::class, $projectId);
        self::assertNotNull($project);
        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        try {
            $deleter->delete($project);
            self::fail('expected the listener exception to propagate');
        } catch (\RuntimeException) {
        }
        $em->clear();

        self::assertNotNull($em->find(Project::class, $projectId));
        $conn = $em->getConnection();
        self::assertSame(2, (int) $conn->fetchOne('SELECT count(*) FROM documents WHERE project_id = :id', ['id' => (string) $projectId]));
        self::assertSame(1, (int) $conn->fetchOne('SELECT count(*) FROM site_review_comments WHERE project_id = :id', ['id' => (string) $projectId]));
        self::assertSame(1, (int) $conn->fetchOne('SELECT count(*) FROM tags WHERE project_id = :id', ['id' => (string) $projectId]));
    }

    private function makeUser(EntityManagerInterface $em, string $slug): User
    {
        $user = new User(
            fullName: 'Deleter Test',
            email: $slug.'@example.test',
            password: 'irrelevant-hash',
        );
        $em->persist($user);

        return $user;
    }

    private function seedFullProject(EntityManagerInterface $em, User $owner, string $slug): Project
    {
        $project = new Project(owner: $owner, name: $slug);
        $em->persist($project);

        $document = new Document(owner: $owner, project: $project, title: $slug.' doc');
        $tag = new Tag(project: $project, name: 'design');
        $em->persist($tag);
        $document->tags->add($tag);
        $em->persist($document);
        // A referenced document too: document_references rows point at documents
        // from both ends, so the join table has to go before either row does.
        $referenced = new Document(owner: $owner, project: $project, title: $slug.' referenced doc');
        $referenced->addVersion('# Referenced', '<h1>Referenced</h1>');
        $em->persist($referenced);
        $document->references->add($referenced);
        $version = $document->addVersion('# Hi', '<h1>Hi</h1>');
        $parent = new Comment(version: $version, author: $owner, body: 'root', anchor: Anchor::unanchored());
        $em->persist($parent);
        $reply = new Comment(version: $version, author: $owner, body: 'reply', anchor: Anchor::unanchored(), parent: $parent);
        $em->persist($reply);
        $em->persist(new Review(version: $version, verdict: Verdict::Approved, reviewer: $owner));
        // A highlight is a third FK onto document_versions, and the constraint is
        // NOT DEFERRABLE — a cleanup that forgets it fails the version delete
        // outright rather than leaving a quiet orphan.
        $em->persist(new Highlight(version: $version, anchor: Anchor::unanchored()));

        // decision_selections.document_id is NOT DEFERRABLE with no ON DELETE
        // CASCADE, so an answered decision aborts the project delete unless
        // DeleteReviewDataOnProjectDeleting clears it. It hangs off the document
        // rather than the version, so it is the other chain's regression guard.
        $em->persist(new DecisionSelection($document, 'deploy-target', 1, 'Ship straight to production', 1));

        $em->persist(new SiteReviewComment(project: $project, position: 0, body: 'widget comment', selector: 'body', text: 'x', url: 'https://example.test/'));
        $em->persist(new SiteReviewEvent($project, 'topic', '{}'));

        [$widgetToken] = ApiToken::issue($owner, $slug.'-widget', ApiTokenScope::SiteReview);
        [$mcpToken] = ApiToken::issue($owner, $slug.'-mcp', ApiTokenScope::Mcp);
        $project->widgetToken = $widgetToken;
        $project->mcpToken = $mcpToken;
        $em->persist($widgetToken);
        $em->persist($mcpToken);

        return $project;
    }
}
