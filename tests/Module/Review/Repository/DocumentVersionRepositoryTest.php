<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Repository;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentVersionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DocumentVersionRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DocumentVersionRepository $documentVersions;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $repo = self::getContainer()->get(DocumentVersionRepository::class);
        self::assertInstanceOf(DocumentVersionRepository::class, $repo);
        $this->documentVersions = $repo;
    }

    public function test_find_latest_returns_the_highest_version_number_not_the_last_inserted(): void
    {
        $owner = new User(username: 'dv-owner', fullName: 'DV Owner', email: 'dv-owner@example.com', password: 'x');
        $this->em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);

        $doc = new Document(owner: $owner, project: $project, title: 'Revision history');
        $doc->addVersion('# v1', '<h1>v1</h1>');
        $doc->addVersion('# v2', '<h1>v2</h1>');
        $v3 = $doc->addVersion('# v3', '<h1>v3</h1>');
        $this->em->persist($doc);
        $this->em->flush();
        $this->em->clear();

        $fetched = $this->em->find(Document::class, $doc->id);
        self::assertInstanceOf(Document::class, $fetched);

        $latest = $this->documentVersions->findLatest($fetched);

        self::assertSame(3, $latest->versionNumber);
        self::assertSame($v3->id?->toRfc4122(), $latest->id?->toRfc4122());
    }

    public function test_find_latest_meta_by_documents_omits_text_columns_and_batches_across_documents(): void
    {
        $owner = new User(username: 'dv-owner2', fullName: 'DV Owner 2', email: 'dv-owner2@example.com', password: 'x');
        $this->em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);

        $docA = new Document(owner: $owner, project: $project, title: 'Doc A');
        $docA->addVersion('# a1', '<h1>a1</h1>');
        $versionA2 = $docA->addVersion('# a2', '<h1>a2</h1>');
        $this->em->persist($docA);

        $docB = new Document(owner: $owner, project: $project, title: 'Doc B');
        $versionB1 = $docB->addVersion('# b1', '<h1>b1</h1>');
        $this->em->persist($docB);

        $this->em->flush();
        $this->em->clear();

        $refetchedA = $this->em->find(Document::class, $docA->id);
        $refetchedB = $this->em->find(Document::class, $docB->id);
        self::assertInstanceOf(Document::class, $refetchedA);
        self::assertInstanceOf(Document::class, $refetchedB);

        $meta = $this->documentVersions->findLatestMetaByDocuments([$refetchedA, $refetchedB]);

        self::assertSame(2, $meta[(string) $docA->id]['versionNumber']);
        self::assertSame($versionA2->id?->toRfc4122(), $meta[(string) $docA->id]['versionId']->toRfc4122());

        self::assertSame(1, $meta[(string) $docB->id]['versionNumber']);
        self::assertSame($versionB1->id?->toRfc4122(), $meta[(string) $docB->id]['versionId']->toRfc4122());
    }

    public function test_find_latest_meta_by_documents_returns_empty_array_for_empty_input(): void
    {
        self::assertSame([], $this->documentVersions->findLatestMetaByDocuments([]));
    }

    public function test_find_all_meta_by_document_carries_each_versions_description(): void
    {
        $owner = new User(username: 'dv-owner3', fullName: 'DV Owner 3', email: 'dv-owner3@example.com', password: 'x');
        $this->em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);

        $doc = new Document(owner: $owner, project: $project, title: 'Described history');
        $doc->addVersion('# v1', '<h1>v1</h1>', 'The original brief.');
        $doc->addVersion('# v2', '<h1>v2</h1>');
        $this->em->persist($doc);
        $this->em->flush();
        $this->em->clear();

        $fetched = $this->em->find(Document::class, $doc->id);
        self::assertInstanceOf(Document::class, $fetched);

        $meta = $this->documentVersions->findAllMetaByDocument($fetched);

        // Newest first.
        self::assertSame([2, 1], array_column($meta, 'versionNumber'));
        self::assertNull($meta[0]['description']);
        self::assertSame('The original brief.', $meta[1]['description']);
    }
}
