<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\AddCommentCommand;
use App\Module\Review\Command\AddCommentHandler;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\ResolveCommentCommand;
use App\Module\Review\Command\ResolveCommentHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ResolveCommentHandlerTest extends KernelTestCase
{
    public function test_marks_comment_as_resolved(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(username: 'resolve-owner', fullName: 'Resolve Owner', email: 'resolve-owner@example.com', password: 'hashed');
        $em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Resolve Test Doc', "# Hello\n\nThis content will be resolved after commenting."));

        /** @var AddCommentHandler $addHandler */
        $addHandler = self::getContainer()->get(AddCommentHandler::class);
        $comment = $addHandler(new AddCommentCommand($owner, $doc, 'will be resolved', '', '', 'This needs resolving'));

        self::assertFalse($comment->resolved, 'Comment must start unresolved');

        /** @var ResolveCommentHandler $resolveHandler */
        $resolveHandler = self::getContainer()->get(ResolveCommentHandler::class);
        $resolveHandler(new ResolveCommentCommand(comment: $comment));

        self::assertTrue($comment->resolved);
    }
}
