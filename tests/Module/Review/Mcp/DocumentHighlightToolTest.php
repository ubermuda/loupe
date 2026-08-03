<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Mcp\DocumentHighlightTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DocumentHighlightToolTest extends KernelTestCase
{
    use McpTokenScenario;

    private const string HTML = '<h1>Key rotation</h1><p>We will issue short-lived JWTs signed with a rotating key.</p>';

    private EntityManagerInterface $em;
    private DocumentHighlightTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(DocumentHighlightTool::class);
        self::assertInstanceOf(DocumentHighlightTool::class, $tool);
        $this->tool = $tool;
    }

    public function test_a_quoted_passage_is_highlighted_on_the_current_version(): void
    {
        $document = $this->document('highlight-tool@example.com');
        $this->actAsMcpTokenBoundTo($document->project);

        $result = ($this->tool)((string) $document->id, ['short-lived JWTs']);

        self::assertSame(['short-lived JWTs'], $result['highlighted']);
        self::assertSame([], $result['skipped']);
        self::assertCount(1, $document->currentVersion()->highlights);
    }

    public function test_an_empty_list_clears_the_set(): void
    {
        $document = $this->document('highlight-tool-clear@example.com');
        $this->actAsMcpTokenBoundTo($document->project);
        ($this->tool)((string) $document->id, ['short-lived JWTs']);
        // Without this the assertions below also pass on a tool that never stored
        // anything in the first place.
        self::assertCount(1, $document->currentVersion()->highlights);

        $result = ($this->tool)((string) $document->id, []);

        self::assertSame([], $result['highlighted']);
        self::assertCount(0, $document->currentVersion()->highlights);
    }

    public function test_a_passage_quoted_as_markdown_is_reported_rather_than_fatal(): void
    {
        $document = $this->document('highlight-tool-markdown@example.com');
        $this->actAsMcpTokenBoundTo($document->project);

        $result = ($this->tool)((string) $document->id, ['**short-lived JWTs**', 'rotating key']);

        self::assertSame(['rotating key'], $result['highlighted']);
        self::assertSame([['quote' => '**short-lived JWTs**', 'reason' => 'not_found']], $result['skipped']);
    }

    public function test_a_document_in_another_project_is_not_reachable(): void
    {
        $mine = $this->document('highlight-tool-mine@example.com');
        $theirs = $this->document('highlight-tool-theirs@example.com');
        $this->actAsMcpTokenBoundTo($mine->project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not found or not accessible');
        ($this->tool)((string) $theirs->id, ['short-lived JWTs']);
    }

    public function test_unbound_mcp_token_is_rejected(): void
    {
        $document = $this->document('highlight-tool-unbound@example.com');
        $this->actAsUnboundMcpToken($document->owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP token is not bound to a project. Mint a project token from the Connect page.');
        ($this->tool)((string) $document->id, ['short-lived JWTs']);
    }

    /** @param non-empty-string $email */
    private function document(string $email): Document
    {
        $owner = new User(username: substr(md5($email), 0, 12), fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($owner);

        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);

        $document = new Document(owner: $owner, project: $project, title: 'Key rotation');
        $document->addVersion('# Key rotation', self::HTML);
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }
}
