<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Entity\DocumentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CreateDocumentHandlerTest extends KernelTestCase
{
    public function test_creates_document_with_first_version(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User(username: 'agent', fullName: 'Agent', email: 'agent@example.com', password: 'hashed-placeholder');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        $handler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $handler(new CreateDocumentCommand($project, 'Auth PRD', "# Auth\n\nUse JWTs."));

        self::assertSame(DocumentStatus::InReview, $doc->status);
        self::assertSame(1, $doc->versions->count());
        self::assertStringContainsString('<h1>Auth</h1>', $doc->currentVersion()->renderedHtml);
    }
}
