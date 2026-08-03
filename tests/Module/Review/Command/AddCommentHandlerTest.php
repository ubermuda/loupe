<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\AddCommentCommand;
use App\Module\Review\Command\AddCommentHandler;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Form\AddCommentFormType;
use App\Module\Review\Form\AddCommentRequest;
use App\Module\Review\Service\AnchorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

final class AddCommentHandlerTest extends KernelTestCase
{
    public function test_creates_comment_on_current_version_with_resolved_anchor(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(username: 'owner', fullName: 'Owner User', email: 'owner@example.com', password: 'hashed');
        $em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Test Doc', "# Hello\n\nThis is the body text here for the comment."));

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
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        // This markdown renders to <h1>Title</h1><p>We use <strong>JWTs</strong> here for auth</p>
        $doc = $createHandler(new CreateDocumentCommand($project, 'Tagged Doc', "# Title\n\nWe use **JWTs** here for auth"));

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

    public function test_unanchored_comment_gets_an_empty_anchor(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(username: 'owner4', fullName: 'Owner', email: 'owner4@example.com', password: 'hashed');
        $em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Doc', "# Hello\n\nSome body."));

        /** @var AddCommentHandler $handler */
        $handler = self::getContainer()->get(AddCommentHandler::class);
        $comment = $handler(new AddCommentCommand($owner, $doc, null, null, null, 'A general note'));

        self::assertSame('', $comment->anchor->quote, 'unanchored comment has no quote');
        self::assertSame('', $comment->anchor->prefix);
        self::assertSame('', $comment->anchor->suffix);
        self::assertSame(0, $comment->anchor->offsetHint);
    }

    public function test_comment_is_orphaned_when_quote_is_not_found_in_current_text(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(username: 'owner5', fullName: 'Owner', email: 'owner5@example.com', password: 'hashed');
        $em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Orphan Doc', "# Hello\n\nSome body text here."));

        // A quote that never appears in the document's plain text — simulates a
        // stale client-captured selection (e.g. the document was revised in
        // another tab between selection and submit).
        /** @var AddCommentHandler $handler */
        $handler = self::getContainer()->get(AddCommentHandler::class);
        $comment = $handler(new AddCommentCommand($owner, $doc, 'this text does not exist anywhere', 'before ', ' after', 'Stale selection'));

        self::assertTrue($comment->orphaned, 'a comment whose quote cannot be located must be marked orphaned, not anchored at offset 0');
        self::assertSame('this text does not exist anywhere', $comment->anchor->quote);
    }

    public function test_rejects_when_actor_does_not_own_document(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(username: 'owner2', fullName: 'Owner', email: 'owner2@example.com', password: 'hashed');
        $nonOwner = new User(username: 'intruder', fullName: 'Intruder', email: 'intruder@example.com', password: 'hashed');
        $em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $em->persist($nonOwner);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Private Doc', "# Secret\n\nConfidential content."));

        /** @var AddCommentHandler $handler */
        $handler = self::getContainer()->get(AddCommentHandler::class);

        $this->expectException(DomainErrors::class);
        $handler(new AddCommentCommand($nonOwner, $doc, 'Confidential', '', '', 'I should not comment here'));
    }

    public function test_boundary_whitespace_survives_the_form_into_the_stored_anchor(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(username: 'owner6', fullName: 'Owner', email: 'owner6@example.com', password: 'hashed');
        $em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Doc', "# T\n\nThis paragraph contains a sample phrase for selection in this review."));

        $plain = $doc->currentVersion()->plainText();
        $quote = 'sample phrase for selection';
        $start = mb_strpos($plain, $quote);
        self::assertIsInt($start);

        // Exactly what the Stimulus controller captures: whitespace-bounded context
        // sliced straight out of the document text.
        $captured = $this->submitAddCommentForm([
            'quote' => $quote,
            'prefix' => mb_substr($plain, max(0, $start - 32), min(32, $start)),
            'suffix' => mb_substr($plain, $start + mb_strlen($quote), 32),
            'body' => 'Why this phrasing?',
        ]);

        self::assertStringEndsWith(' ', $captured->prefix ?? '', 'the form must not trim the captured prefix');
        self::assertStringStartsWith(' ', $captured->suffix ?? '', 'the form must not trim the captured suffix');

        /** @var AddCommentHandler $handler */
        $handler = self::getContainer()->get(AddCommentHandler::class);
        $comment = $handler(new AddCommentCommand($owner, $doc, $captured->quote, $captured->prefix, $captured->suffix, $captured->body ?? ''));

        self::assertSame($captured->prefix, $comment->anchor->prefix);
        self::assertSame($captured->suffix, $comment->anchor->suffix);
    }

    public function test_captured_context_disambiguates_a_repeated_quote_through_the_form(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(username: 'owner7', fullName: 'Owner', email: 'owner7@example.com', password: 'hashed');
        $em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Doc', "# T\n\nCache the token in redis and never log the token in plaintext."));

        // "token" appears twice; the reviewer selected the SECOND one. Both its prefix
        // and suffix fingerprints are whitespace-bounded, so a trimmed context scores
        // zero on every occurrence and the tie hands the anchor to the first — which is
        // what made context disambiguation inert for ordinary selections.
        $plain = $doc->currentVersion()->plainText();
        $second = mb_strrpos($plain, 'token');
        self::assertIsInt($second);
        self::assertNotSame(mb_strpos($plain, 'token'), $second, 'the quote must genuinely repeat');

        $captured = $this->submitAddCommentForm([
            'quote' => 'token',
            'prefix' => mb_substr($plain, max(0, $second - 32), min(32, $second)),
            'suffix' => mb_substr($plain, $second + 5, 32),
            'body' => 'This one leaks.',
        ]);

        /** @var AddCommentHandler $handler */
        $handler = self::getContainer()->get(AddCommentHandler::class);
        $comment = $handler(new AddCommentCommand($owner, $doc, $captured->quote, $captured->prefix, $captured->suffix, $captured->body ?? ''));

        self::assertFalse($comment->orphaned);
        self::assertSame($second, $comment->anchor->offsetHint, 'the captured context must win over earliest-position');
    }

    /**
     * Binds anchor fields the way the real POST does, so the Form component's own
     * value processing is exercised rather than bypassed.
     *
     * @param array<string, string> $fields
     */
    private function submitAddCommentForm(array $fields): AddCommentRequest
    {
        $formFactory = self::getContainer()->get(FormFactoryInterface::class);
        self::assertInstanceOf(FormFactoryInterface::class, $formFactory);

        $form = $formFactory->create(AddCommentFormType::class, new AddCommentRequest(), ['csrf_protection' => false]);
        $form->submit($fields);
        self::assertTrue($form->isValid(), 'the captured anchor must pass validation');

        $data = $form->getData();
        self::assertInstanceOf(AddCommentRequest::class, $data);

        return $data;
    }
}
