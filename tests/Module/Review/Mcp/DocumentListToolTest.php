<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\ListDocumentsHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Mcp\DocumentListTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DocumentListToolTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private DocumentListTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(DocumentListTool::class);
        self::assertInstanceOf(DocumentListTool::class, $tool);
        $this->tool = $tool;
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($user);

        return $user;
    }

    private function project(User $owner): Project
    {
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);

        return $project;
    }

    /**
     * Created through the handler because `Document.searchVector` is written by
     * the indexer alone. A hand-built document is never found by a search.
     *
     * @param list<string> $tagNames
     */
    private function indexedDocument(Project $project, string $title, string $markdown, ?string $description = null, array $tagNames = [], ?string $seriesName = null, ?int $seriesOrdinal = null): Document
    {
        $handler = self::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $handler);

        // The handler writes tag and series rows that point at the project, so
        // the project must already be in the database.
        $this->em->flush();

        return $handler(new CreateDocumentCommand(
            project: $project,
            title: $title,
            markdown: $markdown,
            description: $description,
            tagNames: $tagNames,
            seriesName: $seriesName,
            seriesOrdinal: $seriesOrdinal,
        ));
    }

    /**
     * @param array{documents: list<array<string, mixed>>} $result
     *
     * @return list<mixed>
     */
    private function titles(array $result): array
    {
        return array_column($result['documents'], 'title');
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

        try {
            ($this->tool)();
            self::fail('a broken listing must be reported as a tool call failure');
        } catch (ToolCallException $e) {
            self::assertSame('The document list could not be read. The error has been logged.', $e->getMessage());
            // The original must survive as `previous`: the MCP handler logs the
            // ToolCallException with its chain, which is the only place the real
            // cause is recoverable from.
            self::assertInstanceOf(\LogicException::class, $e->getPrevious());
        }
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
        self::assertSame(ListDocumentsHandler::MAX_PER_PAGE, $result['perPage']);

        $result = ($this->tool)(perPage: 0);
        self::assertSame(1, $result['perPage']);
    }

    public function test_archived_documents_are_omitted_unless_asked_for(): void
    {
        $owner = $this->user('list-archived@example.com');
        $project = $this->project($owner);

        $live = new Document(owner: $owner, project: $project, title: 'Still open');
        $live->addVersion('# L', '<h1>L</h1>');
        $this->em->persist($live);

        $archived = new Document(owner: $owner, project: $project, title: 'Put away');
        $archived->addVersion('# A', '<h1>A</h1>');
        $archived->archivedAt = new \DateTimeImmutable();
        $this->em->persist($archived);
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($project);

        $default = ($this->tool)();
        self::assertSame([(string) $live->id], array_column($default['documents'], 'documentId'));
        self::assertSame(1, $default['total']);
        self::assertFalse($default['documents'][0]['archived']);

        $withArchived = ($this->tool)(includeArchived: true);
        self::assertSame(2, $withArchived['total']);

        $byId = array_column($withArchived['documents'], 'archived', 'documentId');
        self::assertTrue($byId[(string) $archived->id]);
        self::assertFalse($byId[(string) $live->id]);
    }

    public function test_search_keeps_the_matching_documents_and_drops_the_rest(): void
    {
        $owner = $this->user('list-search@example.com');
        $project = $this->project($owner);

        $this->indexedDocument($project, 'Rate limits', '# Rate limits'."\n\n".'A leaky bucket per token.');
        $this->indexedDocument($project, 'Onboarding', '# Onboarding'."\n\n".'The first-run wizard.');

        $this->actAsMcpTokenBoundTo($project);

        $matched = ($this->tool)(search: 'bucket');
        self::assertSame(['Rate limits'], $this->titles($matched));
        self::assertSame(1, $matched['total']);
        self::assertFalse($matched['hasMore']);
    }

    public function test_a_search_that_matches_nothing_returns_an_empty_page(): void
    {
        $owner = $this->user('list-search-miss@example.com');
        $project = $this->project($owner);

        $this->indexedDocument($project, 'Rate limits', '# Rate limits'."\n\n".'A leaky bucket per token.');

        $this->actAsMcpTokenBoundTo($project);

        // The guard: without it an empty answer would also pass on a tool that
        // never reached the index at all.
        self::assertSame(['Rate limits'], $this->titles(($this->tool)(search: 'bucket')));

        $missed = ($this->tool)(search: 'kubernetes');
        self::assertSame([], $missed['documents']);
        self::assertSame(0, $missed['total']);
        self::assertFalse($missed['hasMore']);
    }

    public function test_a_tag_argument_is_normalised_before_it_reaches_the_filter(): void
    {
        $owner = $this->user('list-tag@example.com');
        $project = $this->project($owner);

        $this->indexedDocument($project, 'Tagged', '# Tagged', tagNames: ['design spec']);
        $this->indexedDocument($project, 'Untagged', '# Untagged');

        $this->actAsMcpTokenBoundTo($project);

        // Stored lowercased with its interior run of spaces collapsed, so the
        // raw argument matches nothing unless the tool normalises it.
        $result = ($this->tool)(tag: '  Design   Spec ');
        self::assertSame(['Tagged'], $this->titles($result));
        self::assertSame(1, $result['total']);
    }

    public function test_a_series_argument_is_normalised_before_it_reaches_the_filter(): void
    {
        $owner = $this->user('list-series@example.com');
        $project = $this->project($owner);

        $this->indexedDocument($project, 'Part one', '# Part one', seriesName: 'Rollout Guide', seriesOrdinal: 1);
        $this->indexedDocument($project, 'Part two', '# Part two', seriesName: 'Rollout Guide', seriesOrdinal: 2);
        $this->indexedDocument($project, 'Loose note', '# Loose note');

        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)(series: ' ROLLOUT   guide ');
        self::assertSame(['Part one', 'Part two'], $this->titles($result), 'the series numbering orders the rows');
        self::assertSame(2, $result['total']);
    }

    public function test_the_status_filter_keeps_one_state(): void
    {
        $owner = $this->user('list-status@example.com');
        $project = $this->project($owner);

        $approved = $this->indexedDocument($project, 'Signed off', '# Signed off');
        $approved->status = DocumentStatus::Approved;
        $this->indexedDocument($project, 'Still going', '# Still going');
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(['Signed off'], $this->titles(($this->tool)(status: 'approved')));
        self::assertSame(['Still going'], $this->titles(($this->tool)(status: 'in-review')));
    }

    public function test_an_unknown_status_is_refused_and_the_error_names_the_valid_values(): void
    {
        $owner = $this->user('list-bad-status@example.com');
        $project = $this->project($owner);
        $this->indexedDocument($project, 'Anything', '# Anything');

        $this->actAsMcpTokenBoundTo($project);

        try {
            ($this->tool)(status: 'done');
            self::fail('an unknown status must be refused rather than ignored');
        } catch (ToolCallException $e) {
            self::assertSame('Unknown status "done". Use one of: in-review, approved, changes-requested.', $e->getMessage());
        }
    }

    public function test_a_blank_filter_leaves_the_list_unnarrowed(): void
    {
        $owner = $this->user('list-blank@example.com');
        $project = $this->project($owner);
        $this->indexedDocument($project, 'Only one', '# Only one', tagNames: ['design']);

        $this->actAsMcpTokenBoundTo($project);

        // Normalising a spaces-only argument yields '', which matches no tag and
        // no series — the list must stay unfiltered instead.
        $result = ($this->tool)(search: '   ', status: '', tag: '  ', series: ' ');
        self::assertSame(['Only one'], $this->titles($result));
        self::assertSame(1, $result['total']);
    }

    public function test_each_row_carries_its_current_versions_description(): void
    {
        $owner = $this->user('list-description@example.com');
        $project = $this->project($owner);

        $described = $this->indexedDocument($project, 'Described', '# Described', description: 'Settles the storage question.');
        $bare = $this->indexedDocument($project, 'Bare', '# Bare');

        $this->actAsMcpTokenBoundTo($project);

        $byId = array_column(($this->tool)()['documents'], 'versionDescription', 'documentId');

        self::assertSame('Settles the storage question.', $byId[(string) $described->id]);
        // A version needs no description, and a null must reach the caller
        // rather than fail the row's validation.
        self::assertNull($byId[(string) $bare->id]);
    }
}
