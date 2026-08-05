<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Repository;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DocumentRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DocumentRepository $documents;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $repo = self::getContainer()->get(DocumentRepository::class);
        self::assertInstanceOf(DocumentRepository::class, $repo);
        $this->documents = $repo;
    }

    /** @param non-empty-string $email */
    private function project(string $email): Project
    {
        $user = new User(fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $this->em->persist($project);

        return $project;
    }

    private function document(Project $project, string $title, bool $archived = false): Document
    {
        $document = new Document(owner: $project->owner, project: $project, title: $title);
        $document->addVersion('# '.$title, '<h1>'.$title.'</h1>');
        if ($archived) {
            $document->archivedAt = new \DateTimeImmutable();
        }
        $this->em->persist($document);

        return $document;
    }

    public function test_the_active_count_leaves_out_archived_documents(): void
    {
        $project = $this->project('count-active@example.com');
        $this->document($project, 'Open one');
        $this->document($project, 'Open two');
        $this->document($project, 'Put away', archived: true);
        $this->em->flush();

        // Guard: without it a count of 2 could mean "one was excluded" or
        // "one was never written", and only the first is what this pins.
        self::assertCount(3, $this->documents->findBy(['project' => $project]));
        self::assertSame(2, $this->documents->countActiveByProject($project));
    }

    public function test_the_paginated_list_leaves_out_archived_documents_unless_asked(): void
    {
        $project = $this->project('count-paginated@example.com');
        $live = $this->document($project, 'Open one');
        $archived = $this->document($project, 'Put away', archived: true);
        $this->em->flush();

        $default = iterator_to_array($this->documents->findPaginatedByProject($project, 1, 20), false);
        self::assertSame([$live->id], array_map(static fn (Document $d) => $d->id, $default));

        $all = iterator_to_array($this->documents->findPaginatedByProject($project, 1, 20, includeArchived: true), false);
        $ids = array_map(static fn (Document $d) => $d->id, $all);
        self::assertContains($archived->id, $ids);
        self::assertContains($live->id, $ids);
    }

    /**
     * An archived document still exists and still belongs to its owner, so the
     * path the GDPR export reads must keep returning it.
     */
    public function test_the_owner_lookup_that_feeds_the_export_still_returns_archived_documents(): void
    {
        $project = $this->project('count-export@example.com');
        $this->document($project, 'Open one');
        $archived = $this->document($project, 'Put away', archived: true);
        $this->em->flush();

        $owned = $this->documents->findByOwner($project->owner);

        self::assertCount(2, $owned);
        self::assertContains($archived->id, array_map(static fn (Document $d) => $d->id, $owned));
    }
}
