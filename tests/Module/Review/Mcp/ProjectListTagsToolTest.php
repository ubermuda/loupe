<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\SetDocumentTagsCommand;
use App\Module\Review\Command\SetDocumentTagsHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Mcp\ProjectListTagsTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProjectListTagsToolTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private ProjectListTagsTool $tool;
    private SetDocumentTagsHandler $setTags;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(ProjectListTagsTool::class);
        self::assertInstanceOf(ProjectListTagsTool::class, $tool);
        $this->tool = $tool;

        $setTags = self::getContainer()->get(SetDocumentTagsHandler::class);
        self::assertInstanceOf(SetDocumentTagsHandler::class, $setTags);
        $this->setTags = $setTags;
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'hashed');
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

    public function test_every_tag_is_listed_with_its_document_count(): void
    {
        $owner = $this->user('list-tags@example.com');
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);
        $first = $this->documentIn($project, 'first');
        $second = $this->documentIn($project, 'second');
        $this->em->flush();

        ($this->setTags)(new SetDocumentTagsCommand($first, ['design', 'release']));
        ($this->setTags)(new SetDocumentTagsCommand($second, ['design']));

        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(
            [['name' => 'design', 'documentCount' => 2], ['name' => 'release', 'documentCount' => 1]],
            ($this->tool)()['tags'],
        );
    }

    public function test_a_tag_no_document_carries_any_more_is_still_listed_with_a_zero_count(): void
    {
        $owner = $this->user('list-tags-orphan@example.com');
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);
        $document = $this->documentIn($project, 'only');
        $this->em->flush();

        ($this->setTags)(new SetDocumentTagsCommand($document, ['design-spec']));
        ($this->setTags)(new SetDocumentTagsCommand($document, ['design']));

        $this->actAsMcpTokenBoundTo($project);

        // The zero is the signal that "design-spec" was coined once and dropped;
        // an inner join would hide exactly the row worth seeing.
        self::assertSame(
            [['name' => 'design', 'documentCount' => 1], ['name' => 'design-spec', 'documentCount' => 0]],
            ($this->tool)()['tags'],
        );
    }

    public function test_another_project_vocabulary_is_not_listed(): void
    {
        $owner = $this->user('list-tags-scope@example.com');
        $bound = new Project($owner, 'bound-'.uniqid());
        $other = new Project($owner, 'other-'.uniqid());
        $this->em->persist($bound);
        $this->em->persist($other);
        $foreign = $this->documentIn($other, 'foreign');
        $this->em->flush();

        ($this->setTags)(new SetDocumentTagsCommand($foreign, ['secret']));

        $this->actAsMcpTokenBoundTo($bound);

        self::assertSame([], ($this->tool)()['tags']);
    }

    public function test_unbound_mcp_token_is_rejected(): void
    {
        $owner = $this->user('list-tags-unbound@example.com');

        $this->actAsUnboundMcpToken($owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP token is not bound to a project. Mint a project token from the Connect page.');
        ($this->tool)();
    }
}
