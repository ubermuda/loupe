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

        $version = $doc->currentVersion();
        $plain = $version->plainText();
        $quote = 'body text here';
        $start = strpos($plain, $quote);
        self::assertIsInt($start, 'Quote must exist in plain text');

        /** @var AddCommentHandler $handler */
        $handler = self::getContainer()->get(AddCommentHandler::class);
        $comment = $handler(new AddCommentCommand($owner, $doc, $quote, '', '', 'Great point!'));

        self::assertInstanceOf(Comment::class, $comment);
        self::assertSame($version, $comment->version);
        self::assertFalse($comment->resolved);
        self::assertSame($owner, $comment->author);
        self::assertSame('Great point!', $comment->body);

        /** @var AnchorService $anchorService */
        $anchorService = self::getContainer()->get(AnchorService::class);
        $resolved = $anchorService->resolve($plain, $comment->anchor);
        self::assertSame($start, $resolved, 'Anchor must resolve back to the original offset');
    }

    /**
     * Proves the offset-basis reconciliation: the markdown produces real HTML tags
     * (<h1>, <p>, <strong>), so renderedHtml and plainText() differ. Offsets computed
     * against plainText() must extract the correct quote — not a substring of raw HTML.
     */
    public function test_anchor_quote_is_correct_when_rendered_html_has_tags(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(username: 'owner3', fullName: 'Tag Owner', email: 'owner3@example.com', password: 'hashed');
        $em->persist($owner);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        // This markdown renders to <h1>Title</h1><p>We use <strong>JWTs</strong> here for auth</p>
        $doc = $createHandler(new CreateDocumentCommand($owner, 'Tagged Doc', "# Title\n\nWe use **JWTs** here for auth"));

        $version = $doc->currentVersion();
        $plain = $version->plainText();

        // Verify that the plain text does NOT contain HTML tags
        self::assertStringNotContainsString('<', $plain, 'plainText() must strip all tags');

        $start = strpos($plain, 'JWTs');
        self::assertIsInt($start, '"JWTs" must be found in the plain text');

        /** @var AddCommentHandler $handler */
        $handler = self::getContainer()->get(AddCommentHandler::class);
        $comment = $handler(new AddCommentCommand($owner, $doc, 'JWTs', '', '', 'Why JWTs?'));

        // The stored anchor quote must be exactly the selected phrase, not a slice of raw HTML
        self::assertSame('JWTs', $comment->anchor->quote, 'Anchor quote must match the plain-text selection, not a raw-HTML slice');
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

        /** @var AddCommentHandler $handler */
        $handler = self::getContainer()->get(AddCommentHandler::class);

        $this->expectException(DomainErrors::class);
        $handler(new AddCommentCommand($nonOwner, $doc, 'Confidential', '', '', 'I should not comment here'));
    }
}
