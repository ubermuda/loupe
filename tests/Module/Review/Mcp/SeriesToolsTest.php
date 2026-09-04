<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Mcp\DocumentSetSeriesTool;
use App\Module\Review\Mcp\SeriesListTool;
use App\Module\Review\Mcp\SeriesRenameTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SeriesToolsTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private DocumentSetSeriesTool $setSeries;
    private SeriesListTool $list;
    private SeriesRenameTool $rename;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $setSeries = self::getContainer()->get(DocumentSetSeriesTool::class);
        self::assertInstanceOf(DocumentSetSeriesTool::class, $setSeries);
        $this->setSeries = $setSeries;

        $list = self::getContainer()->get(SeriesListTool::class);
        self::assertInstanceOf(SeriesListTool::class, $list);
        $this->list = $list;

        $rename = self::getContainer()->get(SeriesRenameTool::class);
        self::assertInstanceOf(SeriesRenameTool::class, $rename);
        $this->rename = $rename;
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($user);

        return $user;
    }

    private function documentIn(Project $project, string $title): Document
    {
        $document = new Document($project->owner, $project, $title);
        $document->addVersion('# hi', '<h1>hi</h1>');
        $this->em->persist($document);

        return $document;
    }

    public function test_a_document_is_placed_and_then_taken_out_of_its_series(): void
    {
        $owner = $this->user('series-place@example.com');
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);
        $document = $this->documentIn($project, 'Post five');
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        $placed = ($this->setSeries)((string) $document->id, 'Blog Series', 5);
        self::assertSame(['documentId' => (string) $document->id, 'series' => 'Blog Series', 'seriesOrdinal' => 5], $placed);

        $cleared = ($this->setSeries)((string) $document->id);
        self::assertSame(['documentId' => (string) $document->id, 'series' => null, 'seriesOrdinal' => null], $cleared);
    }

    public function test_a_taken_ordinal_is_reported_as_a_readable_failure(): void
    {
        $owner = $this->user('series-taken@example.com');
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);
        $first = $this->documentIn($project, 'Post one');
        $second = $this->documentIn($project, 'Also post one');
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        ($this->setSeries)((string) $first->id, 'blog series', 1);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Another document in that series already holds that ordinal.');
        ($this->setSeries)((string) $second->id, 'blog series', 1);
    }

    public function test_a_name_without_an_ordinal_is_reported_as_a_readable_failure(): void
    {
        $owner = $this->user('series-no-ordinal@example.com');
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);
        $document = $this->documentIn($project, 'Post five');
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('A series name needs an ordinal beside it, counting from 1.');
        ($this->setSeries)((string) $document->id, 'blog series');
    }

    public function test_every_series_is_listed_with_its_count_and_highest_ordinal(): void
    {
        $owner = $this->user('series-list@example.com');
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);
        $first = $this->documentIn($project, 'Post one');
        $second = $this->documentIn($project, 'Post nine');
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        ($this->setSeries)((string) $first->id, 'Blog Series', 1);
        // A different spelling of the same name is the same series.
        ($this->setSeries)((string) $second->id, 'blog series', 9);

        self::assertSame(
            [['name' => 'Blog Series', 'documentCount' => 2, 'highestOrdinal' => 9]],
            ($this->list)()['series'],
        );
    }

    public function test_another_project_series_is_not_listed(): void
    {
        $owner = $this->user('series-scope@example.com');
        $bound = new Project($owner, 'bound-'.uniqid());
        $other = new Project($owner, 'other-'.uniqid());
        $this->em->persist($bound);
        $this->em->persist($other);
        $foreign = $this->documentIn($other, 'foreign');
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($other);
        ($this->setSeries)((string) $foreign->id, 'secret series', 1);

        $this->actAsMcpTokenBoundTo($bound);
        self::assertSame([], ($this->list)()['series']);
    }

    public function test_a_rename_keeps_every_document_in_place(): void
    {
        $owner = $this->user('series-rename@example.com');
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);
        $document = $this->documentIn($project, 'Post three');
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);
        ($this->setSeries)((string) $document->id, 'blog series', 3);

        self::assertSame(
            ['series' => 'Rust Atomics', 'documentCount' => 1],
            // Named by a spelling nobody stored, because the lookup folds case.
            ($this->rename)('BLOG SERIES', 'Rust Atomics'),
        );
        self::assertSame(3, $document->seriesOrdinal);
    }

    public function test_a_series_of_another_project_cannot_be_renamed(): void
    {
        $owner = $this->user('series-rename-scope@example.com');
        $bound = new Project($owner, 'bound-'.uniqid());
        $other = new Project($owner, 'other-'.uniqid());
        $this->em->persist($bound);
        $this->em->persist($other);
        $foreign = $this->documentIn($other, 'foreign');
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($other);
        ($this->setSeries)((string) $foreign->id, 'secret series', 1);

        $this->actAsMcpTokenBoundTo($bound);

        // The same message a missing series gets, so a token cannot probe what
        // exists outside its project.
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Series "secret series" not found or not accessible.');
        ($this->rename)('secret series', 'stolen');
    }

    public function test_unbound_mcp_tokens_are_rejected_by_both_series_tools(): void
    {
        $owner = $this->user('series-unbound@example.com');
        $this->actAsUnboundMcpToken($owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP token is not bound to a project. Mint a project token from the Connect page.');
        ($this->list)();
    }
}
