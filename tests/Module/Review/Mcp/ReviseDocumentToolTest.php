<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Mcp\ReviseDocumentTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReviseDocumentToolTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private ReviseDocumentTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(ReviseDocumentTool::class);
        self::assertInstanceOf(ReviseDocumentTool::class, $tool);
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

        $summary = ($this->tool)((string) $document->id, '# Revised');

        self::assertSame(['carried' => 0, 'orphaned' => 0], $summary);
        self::assertSame('# Revised', $document->currentVersion()->markdownSource);
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
            ($this->tool)((string) $documentInProjectA->id, '# Hijacked');
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
            ($this->tool)((string) $document->id, '# Hijacked');
            self::fail('revising another user\'s document must throw');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('not found or not accessible', $e->getMessage());
        }

        self::assertSame('# Original', $document->currentVersion()->markdownSource);
    }

    public function test_unbound_mcp_token_is_rejected(): void
    {
        $owner = $this->user('revise-unbound@example.com');
        $document = $this->documentInNewProject($owner, 'Unreachable');

        $this->actAsUnboundMcpToken($owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP token is not bound to a project. Mint a project token from the Connect page.');
        ($this->tool)((string) $document->id, '# Nope');
    }
}
