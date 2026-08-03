<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\SetDocumentTagsCommand;
use App\Module\Review\Command\SetDocumentTagsHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Tag;
use App\Module\Review\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SetDocumentTagsHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SetDocumentTagsHandler $handler;
    private TagRepository $tags;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $handler = self::getContainer()->get(SetDocumentTagsHandler::class);
        self::assertInstanceOf(SetDocumentTagsHandler::class, $handler);
        $this->handler = $handler;

        $tags = self::getContainer()->get(TagRepository::class);
        self::assertInstanceOf(TagRepository::class, $tags);
        $this->tags = $tags;
    }

    /** @return array{Project, Document} */
    private function seed(string $slug): array
    {
        $user = new User(username: $slug, fullName: 'U', email: $slug.'@example.com', password: 'hashed');
        $this->em->persist($user);
        $project = new Project($user, 'p-'.$slug);
        $this->em->persist($project);
        $document = new Document($user, $project, 'doc');
        $document->addVersion('# hi', '<h1>hi</h1>');
        $this->em->persist($document);
        $this->em->flush();

        return [$project, $document];
    }

    /** @return list<string> */
    private function namesOf(Document $document): array
    {
        $names = [];
        foreach ($document->tags as $tag) {
            $names[] = $tag->name;
        }

        return $names;
    }

    public function test_unknown_names_are_created_lowercased_deduplicated_and_sorted(): void
    {
        [$project, $document] = $this->seed('tags-create');

        $applied = ($this->handler)(new SetDocumentTagsCommand($document, ['Release', 'design', ' DESIGN ', '', '  ']));

        self::assertSame(['design', 'release'], array_map(static fn (Tag $t): string => $t->name, $applied));
        self::assertSame(['design', 'release'], $this->namesOf($document));
        self::assertCount(2, $this->tags->findBy(['project' => $project]));
    }

    public function test_an_existing_project_tag_is_reused_rather_than_duplicated(): void
    {
        [$project, $document] = $this->seed('tags-reuse');
        ($this->handler)(new SetDocumentTagsCommand($document, ['design']));

        $second = new Document($project->owner, $project, 'other doc');
        $second->addVersion('# hi', '<h1>hi</h1>');
        $this->em->persist($second);
        $this->em->flush();

        ($this->handler)(new SetDocumentTagsCommand($second, ['Design']));

        self::assertCount(1, $this->tags->findBy(['project' => $project]));
        self::assertSame(['design'], $this->namesOf($second));
    }

    public function test_the_same_name_in_two_projects_is_two_rows(): void
    {
        [$firstProject, $firstDocument] = $this->seed('tags-scope-a');
        [$secondProject, $secondDocument] = $this->seed('tags-scope-b');

        ($this->handler)(new SetDocumentTagsCommand($firstDocument, ['design']));
        ($this->handler)(new SetDocumentTagsCommand($secondDocument, ['design']));

        self::assertCount(1, $this->tags->findBy(['project' => $firstProject]));
        self::assertCount(1, $this->tags->findBy(['project' => $secondProject]));
        self::assertNotSame(
            (string) $this->tags->findOneByProjectAndName($firstProject, 'design')?->id,
            (string) $this->tags->findOneByProjectAndName($secondProject, 'design')?->id,
        );
    }

    public function test_the_set_is_replaced_wholesale_without_deleting_the_tag_row(): void
    {
        [$project, $document] = $this->seed('tags-replace');
        ($this->handler)(new SetDocumentTagsCommand($document, ['design', 'release']));

        ($this->handler)(new SetDocumentTagsCommand($document, ['release']));
        self::assertSame(['release'], $this->namesOf($document));

        ($this->handler)(new SetDocumentTagsCommand($document, []));
        self::assertSame([], $this->namesOf($document));

        // The vocabulary outlives the documents that used it.
        self::assertCount(2, $this->tags->findBy(['project' => $project]));
    }

    public function test_replacing_survives_a_reload_in_the_declared_order(): void
    {
        [, $document] = $this->seed('tags-reload');
        ($this->handler)(new SetDocumentTagsCommand($document, ['release', 'design']));
        $documentId = $document->id;
        $this->em->clear();

        $reloaded = $this->em->find(Document::class, $documentId);
        self::assertInstanceOf(Document::class, $reloaded);
        self::assertSame(['design', 'release'], $this->namesOf($reloaded));
    }

    public function test_an_over_long_name_is_rejected_as_a_domain_error(): void
    {
        [$project, $document] = $this->seed('tags-too-long');

        try {
            ($this->handler)(new SetDocumentTagsCommand($document, [str_repeat('a', Tag::MAX_NAME_LENGTH + 1)]));
            self::fail('expected DomainErrors');
        } catch (DomainErrors $e) {
            self::assertSame(['tags' => 'review.tags.error.too_long'], $e->errors);
        }

        self::assertSame([], $this->tags->findBy(['project' => $project]));
    }
}
