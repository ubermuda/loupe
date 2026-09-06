<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\SetSectionApprovalCommand;
use App\Module\Review\Command\SetSectionApprovalHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Mcp\DocumentGetReviewTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DocumentGetReviewToolTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private DocumentGetReviewTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(DocumentGetReviewTool::class);
        self::assertInstanceOf(DocumentGetReviewTool::class, $tool);
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
        $document->addVersion('# Hello', '<h1>Hello</h1>');
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    public function test_returns_the_review_state_of_the_bound_projects_document(): void
    {
        $owner = $this->user('getreview-bound@example.com');
        $document = $this->documentInNewProject($owner, 'Reviewable');

        $this->actAsMcpTokenBoundTo($document->project);

        $result = ($this->tool)((string) $document->id);

        self::assertSame('in-review', $result['status']);
        self::assertNull($result['verdict']);
        self::assertSame(1, $result['version']);
        self::assertSame([], $result['comments']);
    }

    public function test_cannot_read_the_review_of_another_project_of_the_same_owner(): void
    {
        $owner = $this->user('getreview-cross@example.com');
        $document = $this->documentInNewProject($owner, 'Hidden');

        $projectB = new Project($owner, 'p-'.uniqid());
        $this->em->persist($projectB);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($projectB);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not found or not accessible');
        ($this->tool)((string) $document->id);
    }

    public function test_reports_which_sections_a_reviewer_has_approved(): void
    {
        $owner = $this->user('getreview-sections@example.com');

        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);
        $this->em->flush();

        $create = self::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $create);
        $document = $create(new CreateDocumentCommand(
            $project,
            'Sectioned',
            "## Alpha\n\nAlpha body.\n\n## Beta\n\nBeta body.\n",
        ));

        $approve = self::getContainer()->get(SetSectionApprovalHandler::class);
        self::assertInstanceOf(SetSectionApprovalHandler::class, $approve);
        $approve(new SetSectionApprovalCommand($document, $owner, 'heading-alpha', true, 1));

        $this->actAsMcpTokenBoundTo($project);

        $sections = ($this->tool)((string) $document->id)['sections'];

        self::assertSame(['heading-alpha', 'heading-beta'], array_column($sections, 'heading_id'));
        self::assertSame(1, $sections[0]['standing_approval_count']);
        self::assertSame(0, $sections[1]['standing_approval_count']);
    }

    public function test_unbound_mcp_token_is_rejected(): void
    {
        $owner = $this->user('getreview-unbound@example.com');
        $document = $this->documentInNewProject($owner, 'Unreachable');

        $this->actAsUnboundMcpToken($owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP token is not bound to a project. Mint a project token from the Connect page.');
        ($this->tool)((string) $document->id);
    }
}
