<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Review\Entity\Document;
use App\Module\Review\Mcp\ListDocumentsTool;
use App\Module\Review\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class ListDocumentsToolTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DocumentRepository $documentRepository;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $repo = self::getContainer()->get(DocumentRepository::class);
        self::assertInstanceOf(DocumentRepository::class, $repo);
        $this->documentRepository = $repo;
    }

    public function test_returns_only_authenticated_users_documents(): void
    {
        $userA = new User(
            username: 'user_a',
            fullName: 'User A',
            email: 'user_a@example.com',
            password: 'hashed',
        );
        $userB = new User(
            username: 'user_b',
            fullName: 'User B',
            email: 'user_b@example.com',
            password: 'hashed',
        );
        $this->em->persist($userA);
        $this->em->persist($userB);

        $docA = new Document($userA, 'User A Document');
        $docA->addVersion('# Content A', '<h1>Content A</h1>');
        $this->em->persist($docA);

        $docB = new Document($userB, 'User B Document');
        $docB->addVersion('# Content B', '<h1>Content B</h1>');
        $this->em->persist($docB);

        $this->em->flush();

        // Stub Security to return user A as the authenticated user.
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($userA);

        $tool = new ListDocumentsTool($this->documentRepository, $security);

        $result = $tool();

        // Should return exactly one document — user A's.
        self::assertCount(1, $result);

        $item = $result[0];
        self::assertSame((string) $docA->id, $item['documentId']);
        self::assertSame('User A Document', $item['title']);
        self::assertSame('in-review', $item['status']);
        self::assertSame(1, $item['currentVersion']);

        // User B's document must not appear.
        $returnedIds = array_column($result, 'documentId');
        self::assertNotContains((string) $docB->id, $returnedIds);
    }
}
