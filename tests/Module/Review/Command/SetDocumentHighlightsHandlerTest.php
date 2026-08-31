<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Command\SetDocumentHighlightsCommand;
use App\Module\Review\Command\SetDocumentHighlightsHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Highlight;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SetDocumentHighlightsHandlerTest extends KernelTestCase
{
    private const string HTML = '<h1>Key rotation</h1><p>We will issue short-lived JWTs signed with a rotating key. The key rotates hourly.</p>';

    private EntityManagerInterface $em;
    private SetDocumentHighlightsHandler $handler;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $handler = self::getContainer()->get(SetDocumentHighlightsHandler::class);
        self::assertInstanceOf(SetDocumentHighlightsHandler::class, $handler);
        $this->handler = $handler;
    }

    public function test_a_located_passage_is_stored_with_the_context_the_caller_never_sent(): void
    {
        $document = $this->document('highlight-store@example.com');

        $result = ($this->handler)(new SetDocumentHighlightsCommand($document, ['short-lived JWTs']));

        self::assertSame(['short-lived JWTs'], $result['highlighted']);
        self::assertSame([], $result['skipped']);

        $highlights = $document->currentVersion()->highlights->toArray();
        self::assertCount(1, $highlights);
        $highlight = array_first($highlights);
        self::assertInstanceOf(Highlight::class, $highlight);
        $anchor = $highlight->anchor;
        self::assertSame('short-lived JWTs', $anchor->quote);
        // Sliced out of the document, not echoed back from the call: without it the
        // browser and the server would have nothing to rank a repeated quote by.
        self::assertStringEndsWith('issue ', $anchor->prefix);
        self::assertStringStartsWith(' signed', $anchor->suffix);
    }

    public function test_a_second_call_replaces_the_set_rather_than_adding_to_it(): void
    {
        $document = $this->document('highlight-replace@example.com');
        $documentId = $document->id;
        self::assertNotNull($documentId);

        ($this->handler)(new SetDocumentHighlightsCommand($document, ['short-lived JWTs']));

        // The second call arrives on its own request, where the collection is an
        // UNINITIALIZED PersistentCollection — a different clear() path from the
        // in-memory one, and the only one production ever takes.
        $this->em->clear();
        $document = $this->em->find(Document::class, $documentId);
        self::assertInstanceOf(Document::class, $document);

        ($this->handler)(new SetDocumentHighlightsCommand($document, ['rotates hourly']));

        $highlights = $document->currentVersion()->highlights->toArray();
        self::assertCount(1, $highlights);
        $highlight = array_first($highlights);
        self::assertInstanceOf(Highlight::class, $highlight);
        self::assertSame('rotates hourly', $highlight->anchor->quote);

        // Guard against the replacement leaving the old row orphaned in the table
        // instead of deleting it — the collection above would look right either way.
        $this->em->flush();
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(Highlight::class)->findAll());
    }

    public function test_an_empty_set_clears_every_highlight(): void
    {
        $document = $this->document('highlight-clear@example.com');
        ($this->handler)(new SetDocumentHighlightsCommand($document, ['short-lived JWTs']));
        // Without this the assertions below also pass on a handler that never
        // stored anything in the first place.
        self::assertCount(1, $document->currentVersion()->highlights);

        $result = ($this->handler)(new SetDocumentHighlightsCommand($document, []));

        self::assertSame([], $result['highlighted']);
        self::assertCount(0, $document->currentVersion()->highlights);

        $this->em->flush();
        $this->em->clear();
        self::assertSame([], $this->em->getRepository(Highlight::class)->findAll());
    }

    public function test_each_refusal_reports_its_own_reason_without_losing_the_rest(): void
    {
        $document = $this->document('highlight-mixed@example.com');

        $result = ($this->handler)(new SetDocumentHighlightsCommand($document, [
            '  short-lived JWTs  ',
            // Quoted from the Markdown source rather than the rendered prose.
            '**rotating key**',
            '   ',
            'short-lived JWTs',
            'rotates hourly',
        ]));

        self::assertSame(['short-lived JWTs', 'rotates hourly'], $result['highlighted']);
        // Each skip names the string the caller actually sent — the whitespace-only
        // entry included, which trimming would otherwise report as ''.
        self::assertSame([
            ['quote' => '**rotating key**', 'reason' => 'not_found'],
            ['quote' => '   ', 'reason' => 'blank'],
            ['quote' => 'short-lived JWTs', 'reason' => 'duplicate'],
        ], $result['skipped']);
        self::assertCount(2, $document->currentVersion()->highlights);
    }

    public function test_a_quote_the_source_wrapped_across_lines_still_anchors(): void
    {
        // CommonMark keeps a paragraph's soft wraps, so the plain text holds a
        // newline exactly where a caller quoting the rendered page sends a space.
        $document = $this->document(
            'highlight-wrapped@example.com',
            "<p>We will issue short-lived JWTs signed\nwith a rotating key.</p>",
        );

        $result = ($this->handler)(new SetDocumentHighlightsCommand($document, ['JWTs signed with a rotating key']));

        self::assertSame([], $result['skipped']);
        $highlight = array_first($document->currentVersion()->highlights->toArray());
        self::assertInstanceOf(Highlight::class, $highlight);
        self::assertSame("JWTs signed\nwith a rotating key", $highlight->anchor->quote);
    }

    public function test_two_quotes_that_differ_only_in_wrapping_are_one_highlight(): void
    {
        // They reach the same span now, so the duplicate check has to compare them
        // collapsed or the second one repaints what the first already painted.
        $document = $this->document('highlight-rewrapped@example.com');

        $result = ($this->handler)(new SetDocumentHighlightsCommand($document, [
            'short-lived JWTs',
            "short-lived\nJWTs",
        ]));

        self::assertSame(['short-lived JWTs'], $result['highlighted']);
        self::assertSame([['quote' => "short-lived\nJWTs", 'reason' => 'duplicate']], $result['skipped']);
        self::assertCount(1, $document->currentVersion()->highlights);
    }

    public function test_a_revision_leaves_its_highlights_on_the_version_they_were_written_for(): void
    {
        $document = $this->document('highlight-revision@example.com');
        ($this->handler)(new SetDocumentHighlightsCommand($document, ['short-lived JWTs']));
        $reviewed = $document->currentVersion();
        self::assertCount(1, $reviewed->highlights);

        // Through the real revise handler, not addVersion(): the claim is about
        // what document_revise does, and re-anchoring is the step that would have
        // carried highlights forward had it been asked to.
        $revise = self::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);
        $revise(new ReviseDocumentCommand($document, '# Key rotation', 'Reworded.'));

        // A highlight is pure position with nothing to display once its passage is
        // gone, so a new version simply starts with none and the agent restates
        // what matters in the text it has just written.
        $revised = $document->currentVersion();
        self::assertNotSame($reviewed, $revised);
        self::assertCount(1, $reviewed->highlights);
        self::assertCount(0, $revised->highlights);
    }

    /** @param non-empty-string $email */
    private function document(string $email, string $html = self::HTML): Document
    {
        $owner = new User(fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($owner);

        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);

        $document = new Document(owner: $owner, project: $project, title: 'Key rotation');
        $document->addVersion('# Key rotation', $html);
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    public function test_a_highlight_set_is_recorded_on_the_domain_channel(): void
    {
        $document = $this->document('highlight-audit@example.com');

        ($this->handler)(new SetDocumentHighlightsCommand($document, ['short-lived JWTs', 'nowhere in the text']));

        $record = $this->audit->record('review.document.highlights_updated');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('document', $record->subject->type);
        self::assertSame((string) $document->id, $record->subject->id);
        self::assertSame([
            'documentId' => (string) $document->id,
            'versionId' => (string) $document->currentVersion()->id,
            'highlightedCount' => 1,
            'skippedCount' => 1,
        ], $record->context);

        self::assertSame(['review.document.highlights_updated'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    /** Every quote is document text, so the record counts them instead. */
    public function test_the_record_carries_no_quotes(): void
    {
        $document = $this->document('highlight-audit-quotes@example.com');

        ($this->handler)(new SetDocumentHighlightsCommand($document, ['short-lived JWTs']));

        $context = $this->audit->record('review.document.highlights_updated')->context;
        self::assertArrayNotHasKey('highlighted', $context);
        self::assertArrayNotHasKey('skipped', $context);
        self::assertSame([], array_filter(
            $context,
            static fn (string|int|float|bool|null $value): bool => \is_string($value) && str_contains($value, 'JWT'),
        ));
    }
}
