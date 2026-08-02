<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
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
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'hashed');
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
