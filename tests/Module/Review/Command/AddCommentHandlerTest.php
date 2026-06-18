<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Review\Command\AddCommentCommand;
use App\Module\Review\Command\AddCommentHandler;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Service\AnchorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AddCommentHandlerTest extends KernelTestCase
{
    public function test_creates_comment_on_current_version_with_resolved_anchor(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(username: 'owner', fullName: 'Owner User', email: 'owner@example.com', password: 'hashed');
        $em->persist($owner);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($owner, 'Test Doc', "# Hello\n\nThis is the body text here for the comment."));

        $html = $doc->currentVersion()->renderedHtml;
        $quote = 'body text here';
        $start = strpos((string) $html, $quote);
        self::assertIsInt($start, 'Quote must exist in rendered HTML');
        $length = strlen($quote);

        /** @var AddCommentHandler $handler */
        $handler = self::getContainer()->get(AddCommentHandler::class);
        $comment = $handler(new AddCommentCommand($owner, $doc, $start, $length, 'Great point!'));

        self::assertInstanceOf(Comment::class, $comment);
        self::assertSame($doc->currentVersion(), $comment->version);
        self::assertFalse($comment->resolved);
        self::assertSame($owner, $comment->author);
        self::assertSame('Great point!', $comment->body);

        /** @var AnchorService $anchorService */
        $anchorService = self::getContainer()->get(AnchorService::class);
        $resolved = $anchorService->resolve($html, $comment->anchor);
        self::assertSame($start, $resolved, 'Anchor must resolve back to the original offset');
    }

    public function test_rejects_when_actor_does_not_own_document(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(username: 'owner2', fullName: 'Owner', email: 'owner2@example.com', password: 'hashed');
        $nonOwner = new User(username: 'intruder', fullName: 'Intruder', email: 'intruder@example.com', password: 'hashed');
        $em->persist($owner);
        $em->persist($nonOwner);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($owner, 'Private Doc', "# Secret\n\nConfidential content."));

        $html = $doc->currentVersion()->renderedHtml;
        $start = strpos((string) $html, 'Confidential');
        self::assertIsInt($start);
        $length = strlen('Confidential');

        /** @var AddCommentHandler $handler */
        $handler = self::getContainer()->get(AddCommentHandler::class);

        $this->expectException(DomainErrors::class);
        $handler(new AddCommentCommand($nonOwner, $doc, $start, $length, 'I should not comment here'));
    }
}
