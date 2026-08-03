<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Tag;
use App\Module\Review\Service\DocumentExporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The export reads two lazy collections per document, so getting it wrong costs
 * a query per document rather than a wrong answer — invisible to a test that
 * only checks the exported content.
 */
final class DocumentExporterQueryCountTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DocumentExporter $exporter;
    private DebugDataHolder $queries;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $exporter = self::getContainer()->get(DocumentExporter::class);
        self::assertInstanceOf(DocumentExporter::class, $exporter);
        $this->exporter = $exporter;

        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $this->queries = $queries;
    }

    /** @param non-empty-string $slug */
    private function ownerOfDocuments(string $slug, int $documentCount): User
    {
        $user = new User(username: $slug, fullName: 'U', email: $slug.'@example.com', password: 'hashed');
        $this->em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $this->em->persist($project);

        for ($i = 0; $i < $documentCount; ++$i) {
            $document = new Document(owner: $user, project: $project, title: 'doc '.$i);
            $document->addVersion('# one', '<h1>one</h1>');
            $document->addVersion('# two', '<h1>two</h1>');
            $tag = new Tag($project, 'tag-'.$i);
            $this->em->persist($tag);
            $document->tags->add($tag);
            $this->em->persist($document);
        }

        $this->em->flush();
        $this->em->clear();

        return $user;
    }

    /** @return list<string> */
    private function countQueriesDuringExportOf(User $user): array
    {
        $this->queries->reset();
        $rows = $this->exporter->export($user);

        // Guard: a query count means nothing if the export read no collections.
        // Both are lazy, so touching them is what would fire the extra queries.
        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertNotEmpty($row['tags']);
            self::assertCount(2, $row['versions']);
        }

        $statements = [];
        foreach ($this->queries->getData() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $statements[] = (string) $query['sql'];
            }
        }

        return $statements;
    }

    public function test_exporting_more_documents_does_not_issue_more_queries(): void
    {
        $one = $this->ownerOfDocuments('export-q-one', 1);
        $several = $this->ownerOfDocuments('export-q-many', 5);

        $forOne = $this->countQueriesDuringExportOf($one);
        $forSeveral = $this->countQueriesDuringExportOf($several);

        self::assertNotEmpty($forOne);
        // Equal, not merely "small": a per-document query would make the second
        // export cost four more than the first, and any bound generous enough to
        // pass would also pass with the preloads removed.
        self::assertSame(
            \count($forOne),
            \count($forSeveral),
            "Export query count grew with the number of documents:\n".implode("\n", $forSeveral),
        );
    }
}
