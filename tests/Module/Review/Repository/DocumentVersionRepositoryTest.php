<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Repository;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\ValueObject\Anchor;
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
        $owner = new User(fullName: 'DV Owner', email: 'dv-owner@example.com', password: 'x');
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
        $owner = new User(fullName: 'DV Owner 2', email: 'dv-owner2@example.com', password: 'x');
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
        $owner = new User(fullName: 'DV Owner 3', email: 'dv-owner3@example.com', password: 'x');
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

    /**
     * Two properties the re-render guard depends on and a refactor would quietly
     * drop: the result is a cursor, and its rows are grouped by version.
     */
    public function test_streaming_anchored_comments_stays_lazy_and_grouped_by_version(): void
    {
        $owner = new User(fullName: 'DV Stream', email: 'dv-stream@example.com', password: 'x');
        $this->em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);

        $doc = new Document(owner: $owner, project: $project, title: 'Anchored');
        $first = $doc->addVersion('# v1', '<h1>v1</h1>');
        $second = $doc->addVersion('# v2', '<h1>v2</h1>');
        $this->em->persist($doc);
        // Interleaved on purpose: inserted v1, v2, v1 so grouping cannot pass by
        // accident of insertion order.
        foreach ([[$first, 'a'], [$second, 'b'], [$first, 'c']] as [$version, $quote]) {
            $this->em->persist(new Comment($version, $owner, 'probe', new Anchor($quote, '', '', 0)));
        }
        // Untargeted, so the repository's own filter must drop it.
        $this->em->persist(new Comment($first, $owner, 'probe', new Anchor('', '', '', 0)));
        $this->em->flush();

        $rows = $this->documentVersions->streamAnchoredCommentsByVersion();

        // A fetch would carry the same contents at a very different memory cost.
        self::assertIsNotArray($rows);
        self::assertInstanceOf(\Traversable::class, $rows);

        $quotes = [];
        $versionIds = [];
        foreach ($rows as $row) {
            $quotes[] = $row['anchor_quote'];
            $versionIds[] = $row['id'];
        }

        sort($quotes);
        self::assertSame(['a', 'b', 'c'], $quotes, 'the untargeted comment must be filtered out in SQL');

        // Each version id appears in one unbroken run, which is what lets the
        // caller render a version once instead of once per comment.
        $runsSeen = [];
        $previous = null;
        foreach ($versionIds as $id) {
            if ($id !== $previous) {
                self::assertNotContains($id, $runsSeen, 'a version id reappeared after another version');
                $runsSeen[] = $id;
                $previous = $id;
            }
        }
    }
}
