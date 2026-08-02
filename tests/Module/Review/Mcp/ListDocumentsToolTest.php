<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Mcp\ListDocumentsTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ListDocumentsToolTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private ListDocumentsTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(ListDocumentsTool::class);
        self::assertInstanceOf(ListDocumentsTool::class, $tool);
        $this->tool = $tool;
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($user);

        return $user;
    }

    private function project(User $owner): Project
    {
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);

        return $project;
    }

    public function test_returns_only_the_bound_projects_documents_even_for_the_same_owner(): void
    {
        $owner = $this->user('list-owner@example.com');
        $projectA = $this->project($owner);
        $projectB = $this->project($owner);

        $docA = new Document(owner: $owner, project: $projectA, title: 'Project A Document');
        $docA->addVersion('# Content A', '<h1>Content A</h1>');
        $this->em->persist($docA);

        $docB = new Document(owner: $owner, project: $projectB, title: 'Project B Document');
        $docB->addVersion('# Content B', '<h1>Content B</h1>');
        $this->em->persist($docB);

        $this->em->flush();

        $this->actAsMcpTokenBoundTo($projectA);

        $result = ($this->tool)();

        // The list is wrapped in a `documents` object key — MCP structuredContent
        // must be a JSON object, not a bare array — alongside the page counters.
        self::assertSame(['documents', 'page', 'perPage', 'total', 'hasMore'], array_keys($result));
        self::assertSame(1, $result['total']);
        self::assertFalse($result['hasMore']);

        // Exactly one document — project A's, despite both belonging to the same owner.
        self::assertCount(1, $result['documents']);

        $item = $result['documents'][0];
        self::assertSame((string) $docA->id, $item['documentId']);
        self::assertSame('Project A Document', $item['title']);
        self::assertSame('in-review', $item['status']);
        self::assertSame(1, $item['currentVersion']);

        $returnedIds = array_column($result['documents'], 'documentId');
        self::assertNotContains((string) $docB->id, $returnedIds);
    }

    public function test_another_users_documents_are_not_visible(): void
    {
        $ownerA = $this->user('list-a@example.com');
        $ownerB = $this->user('list-b@example.com');
        $projectA = $this->project($ownerA);
        $projectB = $this->project($ownerB);

        $docB = new Document(owner: $ownerB, project: $projectB, title: 'Foreign Document');
        $docB->addVersion('# B', '<h1>B</h1>');
        $this->em->persist($docB);
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($projectA);

        $result = ($this->tool)();
        self::assertSame([], $result['documents']);
        self::assertSame(0, $result['total']);
        self::assertFalse($result['hasMore']);
    }

    public function test_unbound_mcp_token_is_rejected(): void
    {
        $owner = $this->user('list-unbound@example.com');

        $this->actAsUnboundMcpToken($owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP token is not bound to a project. Mint a project token from the Connect page.');
        ($this->tool)();
    }

    public function test_an_unexpected_failure_is_reported_instead_of_escaping_unwrapped(): void
    {
        $owner = $this->user('list-broken@example.com');
        $project = $this->project($owner);

        // A document with no version is a broken invariant the listing hits as
        // a LogicException; without a catch-all the MCP layer would flatten it
        // to "-32603 Error while executing tool" with no detail at all.
        $this->em->persist(new Document(owner: $owner, project: $project, title: 'Versionless'));
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The document list could not be read. The error has been logged.');
        ($this->tool)();
    }

    public function test_pages_through_the_projects_documents(): void
    {
        $owner = $this->user('list-paged@example.com');
        $project = $this->project($owner);

        for ($i = 1; $i <= 3; ++$i) {
            $doc = new Document(owner: $owner, project: $project, title: 'Document '.$i);
            $doc->addVersion('# D'.$i, '<h1>D'.$i.'</h1>');
            $this->em->persist($doc);
        }
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($project);

        $first = ($this->tool)(page: 1, perPage: 2);
        self::assertCount(2, $first['documents']);
        self::assertSame(3, $first['total']);
        self::assertTrue($first['hasMore']);

        $second = ($this->tool)(page: 2, perPage: 2);
        self::assertCount(1, $second['documents']);
        self::assertSame(3, $second['total']);
        self::assertFalse($second['hasMore'], 'the last page must not claim more follows');

        // No document appears on both pages — the ordering has a unique tiebreak.
        $firstIds = array_column($first['documents'], 'documentId');
        $secondIds = array_column($second['documents'], 'documentId');
        self::assertSame([], array_intersect($firstIds, $secondIds));
    }

    public function test_out_of_range_page_returns_an_empty_page_rather_than_failing(): void
    {
        $owner = $this->user('list-oob@example.com');
        $project = $this->project($owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Only');
        $doc->addVersion('# O', '<h1>O</h1>');
        $this->em->persist($doc);
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)(page: 99);
        self::assertSame([], $result['documents']);
        self::assertSame(1, $result['total']);
        self::assertFalse($result['hasMore']);
    }

    public function test_page_and_per_page_are_clamped_to_sane_bounds(): void
    {
        $owner = $this->user('list-clamp@example.com');
        $project = $this->project($owner);
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)(page: 0, perPage: 10_000);
        self::assertSame(1, $result['page']);
        self::assertSame(ListDocumentsTool::MAX_PER_PAGE, $result['perPage']);

        $result = ($this->tool)(perPage: 0);
        self::assertSame(1, $result['perPage']);
    }
}
