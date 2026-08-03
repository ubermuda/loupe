<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Command\SetDocumentHighlightsCommand;
use App\Module\Review\Command\SetDocumentHighlightsHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Highlight;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SetDocumentHighlightsHandlerTest extends KernelTestCase
{
    private const string HTML = '<h1>Key rotation</h1><p>We will issue short-lived JWTs signed with a rotating key. The key rotates hourly.</p>';

    private EntityManagerInterface $em;
    private SetDocumentHighlightsHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

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
    private function document(string $email): Document
    {
        $owner = new User(username: substr(md5($email), 0, 12), fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($owner);

        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);

        $document = new Document(owner: $owner, project: $project, title: 'Key rotation');
        $document->addVersion('# Key rotation', self::HTML);
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }
}
