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
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use App\Module\Review\Form\AddCommentFormType;
use App\Module\Review\Form\AddCommentRequest;
use App\Module\Review\Service\AnchorService;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final class AddCommentHandlerTest extends KernelTestCase
{
    public function test_stale_annotations_are_rejected_even_when_the_quote_survives(): void
    {
        $document = $this->seedDocument('stale-annotations', "# Title\n\nUnchanged passage.");
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $revise = self::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);
        $revise(new ReviseDocumentCommand($document, "# Title\n\nUnchanged passage.\n\nAdded paragraph.", 'Added context'));
        $handler = self::getContainer()->get(AddCommentHandler::class);
        self::assertInstanceOf(AddCommentHandler::class, $handler);

        foreach ([[null, null], ['Unchanged passage.', null], ['Unchanged passage.', ''], ['Unchanged passage.', 'New wording.']] as [$quote, $replacement]) {
            try {
                $handler(new AddCommentCommand($document->owner, $document, 1, $quote, '', '', 'Draft', $replacement));
                self::fail('A stale annotation must not move to the latest version.');
            } catch (DomainErrors $error) {
                self::assertSame(['versionNumber' => 'review.document.comment.error.stale_version'], $error->errors);
            }
            self::assertTrue($em->isOpen());
        }

        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM comments'));
        $comment = $handler(new AddCommentCommand($document->owner, $document, 2, 'Unchanged passage.', '', '', 'Current draft'));
        self::assertSame(2, $comment->version->versionNumber);
        self::assertFalse($comment->orphaned);
    }

    public function test_creates_comment_on_current_version_with_resolved_anchor(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(fullName: 'Owner User', email: 'owner@example.com', password: 'hashed');
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
        $comment = $handler(new AddCommentCommand($owner, $doc, 1, $quote, '', '', 'Great point!'));

        self::assertInstanceOf(Comment::class, $comment);
        self::assertSame($version, $comment->version);
        self::assertSame(CommentStatus::Pending, $comment->status);
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

        $owner = new User(fullName: 'Tag Owner', email: 'owner3@example.com', password: 'hashed');
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
        $comment = $handler(new AddCommentCommand($owner, $doc, 1, 'JWTs', '', '', 'Why JWTs?'));

        // The stored anchor quote must be exactly the selected phrase, not a slice of raw HTML
        self::assertSame('JWTs', $comment->anchor->quote, 'Anchor quote must match the plain-text selection, not a raw-HTML slice');
    }

    public function test_unanchored_comment_gets_an_empty_anchor(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(fullName: 'Owner', email: 'owner4@example.com', password: 'hashed');
        $em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Doc', "# Hello\n\nSome body."));

        /** @var AddCommentHandler $handler */
        $handler = self::getContainer()->get(AddCommentHandler::class);
        $comment = $handler(new AddCommentCommand($owner, $doc, 1, null, null, null, 'A general note'));

        self::assertSame('', $comment->anchor->quote, 'unanchored comment has no quote');
        self::assertSame('', $comment->anchor->prefix);
        self::assertSame('', $comment->anchor->suffix);
        self::assertSame(0, $comment->anchor->offsetHint);
    }

    public function test_comment_is_orphaned_when_quote_is_not_found_in_current_text(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(fullName: 'Owner', email: 'owner5@example.com', password: 'hashed');
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
        $comment = $handler(new AddCommentCommand($owner, $doc, 1, 'this text does not exist anywhere', 'before ', ' after', 'Stale selection'));

        self::assertTrue($comment->orphaned, 'a comment whose quote cannot be located must be marked orphaned, not anchored at offset 0');
        self::assertSame('this text does not exist anywhere', $comment->anchor->quote);
    }

    public function test_rejects_when_actor_does_not_own_document(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(fullName: 'Owner', email: 'owner2@example.com', password: 'hashed');
        $nonOwner = new User(fullName: 'Intruder', email: 'intruder@example.com', password: 'hashed');
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
        $handler(new AddCommentCommand($nonOwner, $doc, 1, 'Confidential', '', '', 'I should not comment here'));
    }

    public function test_boundary_whitespace_survives_the_form_into_the_stored_anchor(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(fullName: 'Owner', email: 'owner6@example.com', password: 'hashed');
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
        $comment = $handler(new AddCommentCommand($owner, $doc, 1, $captured->quote, $captured->prefix, $captured->suffix, $captured->body ?? ''));

        self::assertSame($captured->prefix, $comment->anchor->prefix);
        self::assertSame($captured->suffix, $comment->anchor->suffix);
    }

    public function test_captured_context_disambiguates_a_repeated_quote_through_the_form(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(fullName: 'Owner', email: 'owner7@example.com', password: 'hashed');
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
        $comment = $handler(new AddCommentCommand($owner, $doc, 1, $captured->quote, $captured->prefix, $captured->suffix, $captured->body ?? ''));

        self::assertFalse($comment->orphaned);
        self::assertSame($second, $comment->anchor->offsetHint, 'the captured context must win over earliest-position');
    }

    public function test_strike_stores_an_empty_replacement_and_no_body(): void
    {
        $doc = $this->seedDocument('striker', "# T\n\nThis sentence should go away entirely.");

        /** @var AddCommentHandler $handler */
        $handler = self::getContainer()->get(AddCommentHandler::class);
        $comment = $handler(new AddCommentCommand($doc->owner, $doc, 1, 'should go away', '', '', '', ''));

        self::assertSame('', $comment->replacement);
        self::assertTrue($comment->isStrike);
        self::assertTrue($comment->isSuggestion, 'a strike is a suggestion with nothing to put back');
        self::assertSame('', $comment->body);
    }

    public function test_rewording_stores_the_replacement_and_is_not_a_strike(): void
    {
        $doc = $this->seedDocument('rewriter', "# T\n\nWe utilise a bespoke solution here.");

        /** @var AddCommentHandler $handler */
        $handler = self::getContainer()->get(AddCommentHandler::class);
        $comment = $handler(new AddCommentCommand($doc->owner, $doc, 1, 'utilise', '', '', 'Plainer word.', 'use'));

        self::assertSame('use', $comment->replacement);
        self::assertTrue($comment->isSuggestion);
        self::assertFalse($comment->isStrike);
    }

    public function test_plain_comment_proposes_no_replacement(): void
    {
        $doc = $this->seedDocument('commenter', "# T\n\nA paragraph worth discussing.");

        /** @var AddCommentHandler $handler */
        $handler = self::getContainer()->get(AddCommentHandler::class);
        $comment = $handler(new AddCommentCommand($doc->owner, $doc, 1, 'worth discussing', '', '', 'Is it?'));

        self::assertNull($comment->replacement);
        self::assertFalse($comment->isSuggestion);
        self::assertFalse($comment->isStrike, 'null must not collapse into the empty-string strike case');
    }

    public function test_rejects_a_replacement_with_no_anchor(): void
    {
        $doc = $this->seedDocument('anchorless', "# T\n\nSome text.");

        /** @var AddCommentHandler $handler */
        $handler = self::getContainer()->get(AddCommentHandler::class);

        $this->expectException(DomainErrors::class);
        $handler(new AddCommentCommand($doc->owner, $doc, 1, null, null, null, '', ''));
    }

    private function seedDocument(string $username, string $markdown): Document
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(fullName: 'Owner', email: $username.'@example.com', password: 'hashed');
        $em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);

        return $createHandler(new CreateDocumentCommand($project, 'Doc', $markdown));
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
        $form->submit(['versionNumber' => '1', ...$fields]);
        self::assertTrue($form->isValid(), 'the captured anchor must pass validation');

        $data = $form->getData();
        self::assertInstanceOf(AddCommentRequest::class, $data);

        return $data;
    }

    public function test_an_added_comment_is_recorded_on_the_domain_channel(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [$owner, $document] = $this->seedForAudit('add-audit@example.com');
        $audit->forget();

        $handler = self::getContainer()->get(AddCommentHandler::class);
        self::assertInstanceOf(AddCommentHandler::class, $handler);
        $comment = $handler(new AddCommentCommand($owner, $document, 1, 'body text here', '', '', 'Great point!'));

        $record = $audit->record('review.comment_added');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('comment', $record->subject->type);
        self::assertSame((string) $comment->id, $record->subject->id);
        self::assertSame([
            'commentId' => (string) $comment->id,
            'documentId' => (string) $document->id,
            'versionId' => (string) $document->currentVersion()->id,
            'orphaned' => false,
            'suggested' => false,
        ], $record->context);

        self::assertSame(['review.comment_added'], $audit->domainLogLines());
        self::assertSame([], $audit->securityLogLines());
    }

    /** The body, the quote and the replacement are all text a person wrote. */
    public function test_the_record_carries_no_comment_text(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [$owner, $document] = $this->seedForAudit('add-audit-text@example.com');
        $audit->forget();

        $handler = self::getContainer()->get(AddCommentHandler::class);
        self::assertInstanceOf(AddCommentHandler::class, $handler);
        $handler(new AddCommentCommand($owner, $document, 1, 'body text here', '', '', 'Ask Dana about this', 'Dana Okafor'));

        $context = $audit->record('review.comment_added')->context;
        self::assertTrue($context['suggested']);
        self::assertSame([], array_filter(
            $context,
            static fn (string|int|float|bool|null $value): bool => \is_string($value) && str_contains($value, 'Dana'),
        ));
    }

    public function test_an_unlocatable_quote_is_recorded_as_orphaned(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [$owner, $document] = $this->seedForAudit('add-audit-orphan@example.com');
        $audit->forget();

        $handler = self::getContainer()->get(AddCommentHandler::class);
        self::assertInstanceOf(AddCommentHandler::class, $handler);
        $handler(new AddCommentCommand($owner, $document, 1, 'nowhere in this document', '', '', 'stale'));

        self::assertTrue($audit->record('review.comment_added')->context['orphaned']);
    }

    public function test_a_refused_comment_records_nothing(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [, $document] = $this->seedForAudit('add-audit-refused@example.com');

        $stranger = new User(fullName: 'Stranger', email: 'add-audit-stranger@example.com', password: 'hashed');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->persist($stranger);
        $em->flush();
        $audit->forget();

        $handler = self::getContainer()->get(AddCommentHandler::class);
        self::assertInstanceOf(AddCommentHandler::class, $handler);

        try {
            $handler(new AddCommentCommand($stranger, $document, 1, null, null, null, 'not mine'));
            self::fail('a non-owner must be rejected');
        } catch (DomainErrors) {
        }

        self::assertSame([], $audit->operations());
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{User, Document}
     */
    private function seedForAudit(string $email): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $owner = new User(fullName: 'Owner', email: $email, password: 'hashed');
        $em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        $create = self::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $create);

        return [$owner, $create(new CreateDocumentCommand($project, 'Doc', "# Hello\n\nThis is the body text here for the comment."))];
    }
}
