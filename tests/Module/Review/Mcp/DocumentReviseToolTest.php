<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Mcp\DocumentCreateTool;
use App\Module\Review\Mcp\DocumentReviseTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DocumentReviseToolTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private DocumentReviseTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(DocumentReviseTool::class);
        self::assertInstanceOf(DocumentReviseTool::class, $tool);
        $this->tool = $tool;
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($user);

        return $user;
    }

    private function documentInNewProject(User $owner, string $title): Document
    {
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);

        $document = new Document(owner: $owner, project: $project, title: $title);
        $document->addVersion('# Original', '<h1>Original</h1>');
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    private function documentIn(Project $project, string $title): Document
    {
        $document = new Document(owner: $project->owner, project: $project, title: $title);
        $document->addVersion('# Original', '<h1>Original</h1>');
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    public function test_revises_a_document_of_the_bound_project(): void
    {
        $owner = $this->user('revise-bound@example.com');
        $document = $this->documentInNewProject($owner, 'Mine');

        $this->actAsMcpTokenBoundTo($document->project);

        $summary = ($this->tool)((string) $document->id, '# Revised', 'Rewrote the body.');

        self::assertSame(['carried' => 0, 'orphaned' => 0], $summary);
        self::assertSame('# Revised', $document->currentVersion()->markdownSource);
    }

    public function test_a_title_passed_alongside_the_markdown_corrects_the_document(): void
    {
        $owner = $this->user('revise-title@example.com');
        $document = $this->documentInNewProject($owner, 'Eight features — design spec');

        $this->actAsMcpTokenBoundTo($document->project);

        ($this->tool)((string) $document->id, '# Nine', 'Added a ninth feature.', 'Nine features — design spec');

        self::assertSame('Nine features — design spec', $document->title);
    }

    public function test_omitting_the_title_leaves_it_alone(): void
    {
        $owner = $this->user('revise-no-title@example.com');
        $document = $this->documentInNewProject($owner, 'Keep this title');

        $this->actAsMcpTokenBoundTo($document->project);

        ($this->tool)((string) $document->id, '# Revised', 'Tightened the wording.');

        self::assertSame('Keep this title', $document->title);
    }

    public function test_the_description_is_stored_on_the_new_version(): void
    {
        $owner = $this->user('revise-description@example.com');
        $document = $this->documentInNewProject($owner, 'Described');

        $this->actAsMcpTokenBoundTo($document->project);

        ($this->tool)((string) $document->id, '# Revised', '  Replaced the rollout section.  ');

        self::assertSame('Replaced the rollout section.', $document->currentVersion()->description);
    }

    public function test_a_blank_description_keeps_its_own_message(): void
    {
        $owner = $this->user('revise-blank-desc@example.com');
        $document = $this->documentInNewProject($owner, 'Undescribed');

        $this->actAsMcpTokenBoundTo($document->project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('A description of what changed in this version is required.');
        ($this->tool)((string) $document->id, '# Revised', '   ');
    }

    public function test_a_blank_title_keeps_its_own_message(): void
    {
        $owner = $this->user('revise-blank-title@example.com');
        $document = $this->documentInNewProject($owner, 'Keep this title');

        $this->actAsMcpTokenBoundTo($document->project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('A document title must not be blank.');
        ($this->tool)((string) $document->id, '# Revised', 'Tightened the wording.', '   ');
    }

    public function test_a_reference_list_replaces_the_whole_set(): void
    {
        $owner = $this->user('revise-references@example.com');
        $document = $this->documentInNewProject($owner, 'Thread');
        $first = $this->documentIn($document->project, 'Post one');
        $second = $this->documentIn($document->project, 'Post two');

        $this->actAsMcpTokenBoundTo($document->project);

        ($this->tool)((string) $document->id, '# One', 'Pointed at post one.', null, [(string) $first->id]);
        self::assertSame(['Post one'], $this->referenceTitles($document));

        ($this->tool)((string) $document->id, '# Two', 'Repointed at post two.', null, [(string) $second->id]);
        self::assertSame(['Post two'], $this->referenceTitles($document));
    }

    public function test_omitting_references_keeps_them_and_an_empty_list_clears_them(): void
    {
        $owner = $this->user('revise-ref-keep@example.com');
        $document = $this->documentInNewProject($owner, 'Thread');
        $target = $this->documentIn($document->project, 'The post');

        $this->actAsMcpTokenBoundTo($document->project);

        ($this->tool)((string) $document->id, '# One', 'Linked the post.', null, [(string) $target->id]);
        ($this->tool)((string) $document->id, '# Two', 'Tightened the wording.');
        self::assertSame(['The post'], $this->referenceTitles($document));

        ($this->tool)((string) $document->id, '# Three', 'Dropped the link.', null, []);
        self::assertSame([], $this->referenceTitles($document));
    }

    public function test_the_same_reference_twice_is_one_link(): void
    {
        $owner = $this->user('revise-ref-dupe@example.com');
        $document = $this->documentInNewProject($owner, 'Thread');
        $target = $this->documentIn($document->project, 'The post');

        $this->actAsMcpTokenBoundTo($document->project);

        ($this->tool)((string) $document->id, '# One', 'Linked the post.', null, [(string) $target->id, (string) $target->id]);

        self::assertSame(['The post'], $this->referenceTitles($document));
    }

    public function test_a_reference_to_an_archived_document_is_kept(): void
    {
        $owner = $this->user('revise-ref-arch@example.com');
        $document = $this->documentInNewProject($owner, 'Thread');
        $target = $this->documentIn($document->project, 'The retired spec');
        $target->archivedAt = new \DateTimeImmutable();
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($document->project);

        ($this->tool)((string) $document->id, '# One', 'Linked the retired spec.', null, [(string) $target->id]);

        self::assertSame(['The retired spec'], $this->referenceTitles($document));
    }

    public function test_a_self_reference_is_rejected(): void
    {
        $owner = $this->user('revise-self-ref@example.com');
        $document = $this->documentInNewProject($owner, 'Thread');

        $this->actAsMcpTokenBoundTo($document->project);

        try {
            ($this->tool)((string) $document->id, '# One', 'Pointed at myself.', null, [(string) $document->id]);
            self::fail('a self-reference must throw');
        } catch (ToolCallException $e) {
            self::assertSame('A document cannot reference itself.', $e->getMessage());
        }

        // Rejected outright: the revision itself did not land either.
        self::assertSame('# Original', $document->currentVersion()->markdownSource);
    }

    /**
     * The bad id sits between two good ones, and the document already has
     * references: a set that were only checked as it was written would clear the
     * originals, add the first target and abandon the rest.
     */
    public function test_a_reference_to_another_projects_document_rejects_the_whole_revision(): void
    {
        $owner = $this->user('revise-xref@example.com');
        $document = $this->documentInNewProject($owner, 'Thread');
        $first = $this->documentIn($document->project, 'Post one');
        $second = $this->documentIn($document->project, 'Post two');
        $foreign = $this->documentInNewProject($owner, 'Elsewhere');

        $this->actAsMcpTokenBoundTo($document->project);

        ($this->tool)((string) $document->id, '# One', 'Linked both posts.', null, [(string) $first->id, (string) $second->id]);
        self::assertSame(['Post one', 'Post two'], $this->referenceTitles($document));

        try {
            ($this->tool)(
                (string) $document->id,
                '# Two',
                'Pointed across projects.',
                null,
                [(string) $first->id, (string) $foreign->id, (string) $second->id],
            );
            self::fail('referencing another project\'s document must throw');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('not found or not accessible', $e->getMessage());
        }

        // Nothing moved: not the references, not the markdown.
        self::assertSame(['Post one', 'Post two'], $this->referenceTitles($document));
        self::assertSame('# One', $document->currentVersion()->markdownSource);
    }

    /**
     * The handlers are callable without going through the MCP resolver that
     * scopes ids to the bound project, so the rule has to hold there too.
     */
    public function test_the_handler_rejects_a_cross_project_reference_on_its_own(): void
    {
        $owner = $this->user('revise-xref-dom@example.com');
        $document = $this->documentInNewProject($owner, 'Thread');
        $foreign = $this->documentInNewProject($owner, 'Elsewhere');

        $handler = self::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $handler);

        try {
            $handler(new ReviseDocumentCommand($document, '# Two', 'Pointed across projects.', null, [$foreign]));
            self::fail('the handler must reject a reference from another project');
        } catch (DomainErrors $e) {
            self::assertSame(['references' => 'review.references.error.other_project'], $e->errors);
        }

        self::assertSame('# Original', $document->currentVersion()->markdownSource);
    }

    /**
     * Read back from the join table rather than the in-memory collection, so a
     * set that was only ever mutated in memory cannot pass as a stored one.
     *
     * @return list<string>
     */
    private function referenceTitles(Document $document): array
    {
        return array_map(strval(...), $this->em->getConnection()->fetchFirstColumn(
            'SELECT d.title FROM document_references r JOIN documents d ON r.target_document_id = d.id WHERE r.source_document_id = :id ORDER BY d.title',
            ['id' => (string) $document->id],
        ));
    }

    public function test_cannot_revise_a_document_of_another_project_of_the_same_owner(): void
    {
        $owner = $this->user('revise-cross@example.com');
        $documentInProjectA = $this->documentInNewProject($owner, 'Project A Doc');

        // Token bound to a DIFFERENT project (B) of the very same owner.
        $projectB = new Project($owner, 'p-'.uniqid());
        $this->em->persist($projectB);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($projectB);

        try {
            ($this->tool)((string) $documentInProjectA->id, '# Hijacked', 'Nope.');
            self::fail('revising another project\'s document must throw');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('not found or not accessible', $e->getMessage());
        }

        // The document must be untouched.
        self::assertSame('# Original', $documentInProjectA->currentVersion()->markdownSource);
    }

    public function test_cannot_revise_another_users_document(): void
    {
        $victim = $this->user('revise-victim@example.com');
        $document = $this->documentInNewProject($victim, 'Victim Doc');

        $attacker = $this->user('revise-attacker@example.com');
        $attackerProject = new Project($attacker, 'p-'.uniqid());
        $this->em->persist($attackerProject);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($attackerProject);

        try {
            ($this->tool)((string) $document->id, '# Hijacked', 'Nope.');
            self::fail('revising another user\'s document must throw');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('not found or not accessible', $e->getMessage());
        }

        self::assertSame('# Original', $document->currentVersion()->markdownSource);
    }

    public function test_oversized_markdown_keeps_its_own_message(): void
    {
        $owner = $this->user('revise-oversized@example.com');
        $document = $this->documentInNewProject($owner, 'Too Big');

        $this->actAsMcpTokenBoundTo($document->project);

        // The try covers resolution and the size check as well as the handler,
        // so without the ToolCallException re-throw guard this message would be
        // rewritten into the catch-all's generic one.
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The markdown content exceeds the maximum allowed size.');
        ($this->tool)((string) $document->id, str_repeat('a', DocumentCreateTool::MAX_MARKDOWN_BYTES + 1), 'Too big.');
    }

    public function test_a_malformed_document_id_keeps_its_own_message(): void
    {
        $owner = $this->user('revise-malformed@example.com');
        $document = $this->documentInNewProject($owner, 'Whatever');

        $this->actAsMcpTokenBoundTo($document->project);

        // Raised during resolution, which is now inside the try.
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"not-a-uuid" is not a valid document ID.');
        ($this->tool)('not-a-uuid', '# Fine', 'Fine.');
    }

    public function test_unbound_mcp_token_is_rejected(): void
    {
        $owner = $this->user('revise-unbound@example.com');
        $document = $this->documentInNewProject($owner, 'Unreachable');

        $this->actAsUnboundMcpToken($owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP token is not bound to a project. Mint a project token from the Connect page.');
        ($this->tool)((string) $document->id, '# Nope', 'Nope.');
    }
}
