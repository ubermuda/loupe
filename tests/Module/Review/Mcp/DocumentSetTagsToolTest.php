<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Tag;
use App\Module\Review\Mcp\DocumentSetTagsTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DocumentSetTagsToolTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private DocumentSetTagsTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(DocumentSetTagsTool::class);
        self::assertInstanceOf(DocumentSetTagsTool::class, $tool);
        $this->tool = $tool;
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($user);

        return $user;
    }

    private function documentIn(Project $project): Document
    {
        $document = new Document($project->owner, $project, 'doc');
        $document->addVersion('# hi', '<h1>hi</h1>');
        $this->em->persist($document);

        return $document;
    }

    public function test_tags_are_replaced_wholesale_and_returned_normalised(): void
    {
        $owner = $this->user('set-tags@example.com');
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);
        $document = $this->documentIn($project);
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)((string) $document->id, ['Release', 'design']);
        self::assertSame(['design', 'release'], $result['tags']);
        self::assertSame((string) $document->id, $result['documentId']);

        $result = ($this->tool)((string) $document->id, ['release']);
        self::assertSame(['release'], $result['tags']);

        $this->em->clear();
        $reloaded = $this->em->find(Document::class, Uuid::fromString($result['documentId']));
        self::assertInstanceOf(Document::class, $reloaded);
        self::assertSame(['release'], array_map(static fn (Tag $t): string => $t->name, $reloaded->tags->toArray()));
    }

    public function test_a_document_in_another_project_is_not_reachable(): void
    {
        $owner = $this->user('set-tags-scope@example.com');
        $bound = new Project($owner, 'bound-'.uniqid());
        $other = new Project($owner, 'other-'.uniqid());
        $this->em->persist($bound);
        $this->em->persist($other);
        $foreign = $this->documentIn($other);
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($bound);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not found or not accessible');
        ($this->tool)((string) $foreign->id, ['design']);
    }

    public function test_an_over_long_tag_name_is_reported_to_the_agent(): void
    {
        $owner = $this->user('set-tags-long@example.com');
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);
        $document = $this->documentIn($project);
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(\sprintf('A tag name must be at most %d characters.', Tag::MAX_NAME_LENGTH));
        ($this->tool)((string) $document->id, [str_repeat('a', Tag::MAX_NAME_LENGTH + 1)]);
    }
}
