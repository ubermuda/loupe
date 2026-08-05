<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Mcp\DocumentSetReferencesTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DocumentSetReferencesToolTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private DocumentSetReferencesTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(DocumentSetReferencesTool::class);
        self::assertInstanceOf(DocumentSetReferencesTool::class, $tool);
        $this->tool = $tool;
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($user);

        return $user;
    }

    private function projectOf(User $owner): Project
    {
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    private function documentIn(Project $project, string $title): Document
    {
        $document = new Document(owner: $project->owner, project: $project, title: $title);
        $document->addVersion('# hi', '<h1>hi</h1>');
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    public function test_the_set_is_replaced_wholesale_without_adding_a_version(): void
    {
        $project = $this->projectOf($this->user('set-refs@example.com'));
        $source = $this->documentIn($project, 'source');
        $first = $this->documentIn($project, 'first');
        $second = $this->documentIn($project, 'second');

        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)((string) $source->id, [(string) $first->id, (string) $second->id]);
        self::assertSame((string) $source->id, $result['documentId']);
        self::assertSame([(string) $first->id, (string) $second->id], $result['references']);

        $result = ($this->tool)((string) $source->id, [(string) $second->id]);
        self::assertSame([(string) $second->id], $result['references']);

        self::assertCount(1, $source->versions);
    }

    public function test_an_empty_list_clears_the_references(): void
    {
        $project = $this->projectOf($this->user('set-refs-clear@example.com'));
        $source = $this->documentIn($project, 'source');
        $target = $this->documentIn($project, 'target');

        $this->actAsMcpTokenBoundTo($project);
        ($this->tool)((string) $source->id, [(string) $target->id]);

        $result = ($this->tool)((string) $source->id, []);
        self::assertSame([], $result['references']);

        $sourceId = $source->id;
        $this->em->clear();
        $reloaded = $this->em->find(Document::class, $sourceId);
        self::assertInstanceOf(Document::class, $reloaded);
        self::assertCount(0, $reloaded->references);
    }

    /**
     * $referencedBy is derived from the owning side and populated only when the
     * document is loaded, so the target is reloaded rather than read in place —
     * the writing request never sees its own incoming link.
     */
    public function test_the_link_is_navigable_from_the_target_after_a_reload(): void
    {
        $project = $this->projectOf($this->user('set-refs-incoming@example.com'));
        $source = $this->documentIn($project, 'source');
        $target = $this->documentIn($project, 'target');

        $this->actAsMcpTokenBoundTo($project);
        ($this->tool)((string) $source->id, [(string) $target->id]);

        $targetId = $target->id;
        $this->em->clear();
        $reloadedTarget = $this->em->find(Document::class, $targetId);
        self::assertInstanceOf(Document::class, $reloadedTarget);
        self::assertSame(['source'], array_map(
            static fn (Document $d): string => $d->title,
            $reloadedTarget->referencedBy->toArray(),
        ));
    }

    public function test_a_source_document_in_another_project_is_not_reachable(): void
    {
        $owner = $this->user('refs-src-scope@example.com');
        $foreign = $this->documentIn($this->projectOf($owner), 'foreign source');
        $bound = $this->projectOf($owner);
        $target = $this->documentIn($bound, 'target');

        $this->actAsMcpTokenBoundTo($bound);

        try {
            ($this->tool)((string) $foreign->id, [(string) $target->id]);
            self::fail('referencing from another project\'s document must throw');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('not found or not accessible', $e->getMessage());
        }

        self::assertCount(0, $foreign->references);
    }

    public function test_a_target_document_in_another_project_is_not_reachable(): void
    {
        $owner = $this->user('refs-tgt-scope@example.com');
        $bound = $this->projectOf($owner);
        $source = $this->documentIn($bound, 'source');
        $foreign = $this->documentIn($this->projectOf($owner), 'foreign target');

        $this->actAsMcpTokenBoundTo($bound);

        try {
            ($this->tool)((string) $source->id, [(string) $foreign->id]);
            self::fail('referencing another project\'s document must throw');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('not found or not accessible', $e->getMessage());
        }

        self::assertCount(0, $source->references);
    }

    public function test_cannot_reference_from_another_users_document(): void
    {
        $victim = $this->user('set-refs-victim@example.com');
        $victimProject = $this->projectOf($victim);
        $document = $this->documentIn($victimProject, 'Victim Doc');

        $attackerProject = $this->projectOf($this->user('set-refs-attacker@example.com'));
        $attackerDocument = $this->documentIn($attackerProject, 'Attacker Doc');

        $this->actAsMcpTokenBoundTo($attackerProject);

        try {
            ($this->tool)((string) $document->id, [(string) $attackerDocument->id]);
            self::fail('referencing from another user\'s document must throw');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('not found or not accessible', $e->getMessage());
        }

        self::assertCount(0, $document->references);
    }

    public function test_a_self_reference_is_reported_to_the_agent(): void
    {
        $project = $this->projectOf($this->user('set-refs-self@example.com'));
        $source = $this->documentIn($project, 'source');

        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('A document cannot reference itself.');
        ($this->tool)((string) $source->id, [(string) $source->id]);
    }

    public function test_unbound_mcp_token_is_rejected(): void
    {
        $owner = $this->user('set-refs-unbound@example.com');
        $project = $this->projectOf($owner);
        $source = $this->documentIn($project, 'source');
        $target = $this->documentIn($project, 'target');

        $this->actAsUnboundMcpToken($owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP token is not bound to a project. Mint a project token from the Connect page.');
        ($this->tool)((string) $source->id, [(string) $target->id]);
    }
}
