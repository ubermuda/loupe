<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Command\SelectDecisionOptionCommand;
use App\Module\Review\Command\SelectDecisionOptionHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DecisionSelectionRepository;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SelectDecisionOptionHandlerTest extends KernelTestCase
{
    private const string MARKDOWN = <<<'MD'
        Pick a target.

        <!-- decision: deploy-target -->

        - [ ] Ship to staging first
        - [ ] Ship straight to production

        <!-- /decision -->
        MD;

    private EntityManagerInterface $em;
    private SelectDecisionOptionHandler $selectDecisionOption;
    private DecisionSelectionRepository $selections;
    private Project $project;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $handler = self::getContainer()->get(SelectDecisionOptionHandler::class);
        self::assertInstanceOf(SelectDecisionOptionHandler::class, $handler);
        $this->selectDecisionOption = $handler;

        $selections = self::getContainer()->get(DecisionSelectionRepository::class);
        self::assertInstanceOf(DecisionSelectionRepository::class, $selections);
        $this->selections = $selections;

        $owner = new User(
            fullName: 'Decider',
            email: 'decider-'.uniqid().'@example.com',
            password: 'hashed',
        );
        $this->em->persist($owner);
        $this->project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($this->project);
        $this->em->flush();
    }

    public function test_records_the_chosen_option_against_the_decision_identifier(): void
    {
        $document = $this->createDocument(self::MARKDOWN);

        $selection = ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'deploy-target', 1, displayedVersionNumber: 1));

        self::assertSame('deploy-target', $selection->decisionId);
        self::assertSame(1, $selection->optionIndex);
        self::assertSame('Ship straight to production', $selection->optionLabel);
        self::assertSame(1, $selection->versionNumber);
    }

    /**
     * The unique (document, decision) index makes a second insert a 500 rather
     * than an update, and answering is a click — a reviewer changing their mind
     * is the normal case, not an edge one.
     *
     * The concurrent form of this is not expressible here: dama wraps the test
     * in one connection's transaction, so two overlapping DB transactions cannot
     * exist. The row lock in the handler is what covers that; this is the
     * sequential regression guard.
     */
    public function test_answering_again_updates_the_one_row_rather_than_inserting_a_second(): void
    {
        $document = $this->createDocument(self::MARKDOWN);

        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'deploy-target', 0, displayedVersionNumber: 1));
        $second = ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'deploy-target', 1, displayedVersionNumber: 1));

        self::assertCount(1, $this->selections->findBy(['document' => $document]));
        self::assertSame(1, $second->optionIndex);
        self::assertSame('Ship straight to production', $second->optionLabel);
    }

    /**
     * The whole reason the identifier keys the answer rather than the quoted
     * text: a revision responding to feedback about a decision is exactly the
     * revision that rewords it.
     */
    public function test_an_answer_survives_a_revision_that_rewords_its_block(): void
    {
        $document = $this->createDocument(self::MARKDOWN);
        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'deploy-target', 1, displayedVersionNumber: 1));

        $revise = self::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);
        $revise(new ReviseDocumentCommand(
            $document,
            str_replace(
                ['Pick a target.', 'Ship straight to production'],
                ['Choose where this lands.', 'Deploy directly to production'],
                self::MARKDOWN,
            ),
            'Reworded the deploy decision.',
        ));

        $selection = $this->selections->findOneByDocumentAndDecisionId($document, 'deploy-target');
        self::assertNotNull($selection);
        self::assertSame(1, $selection->optionIndex);
        // The label is the one that was agreed to, not the rewritten one.
        self::assertSame('Ship straight to production', $selection->optionLabel);
    }

    /**
     * An index describes the list it was rendered from, so once a revision has
     * replaced that list the submission means nothing — even when the decision
     * id and the index are both still in range, which is the case that would
     * otherwise pass silently.
     */
    public function test_an_answer_describing_a_superseded_version_is_rejected(): void
    {
        $document = $this->createDocument(self::MARKDOWN);

        $revise = self::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);
        $revise(new ReviseDocumentCommand(
            $document,
            "Pick a target.\n\n<!-- decision: deploy-target -->\n\n- [ ] Ship straight to production\n- [ ] Ship to staging first\n\n<!-- /decision -->\n",
            'Reordered the options.',
        ));

        $this->expectException(DomainErrors::class);

        try {
            ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'deploy-target', 1, displayedVersionNumber: 1));
        } finally {
            // Names the label so a regression reports WHICH answer it invented:
            // index 1 was 'Ship straight to production' on the version the
            // reviewer read, and is 'Ship to staging first' on the current one.
            $persisted = $this->selections->findOneByDocumentAndDecisionId($document, 'deploy-target');
            self::assertNull($persisted, 'recorded an answer the reviewer never gave: '.($persisted->optionLabel ?? ''));
        }
    }

    public function test_a_decision_the_current_version_does_not_offer_is_rejected(): void
    {
        $document = $this->createDocument(self::MARKDOWN);

        $this->expectException(DomainErrors::class);
        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'no-such-decision', 0, displayedVersionNumber: 1));
    }

    public function test_an_option_index_outside_the_block_is_rejected(): void
    {
        $document = $this->createDocument(self::MARKDOWN);

        $this->expectException(DomainErrors::class);
        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'deploy-target', 7, displayedVersionNumber: 1));
    }

    public function test_a_selection_is_recorded_on_the_domain_channel(): void
    {
        $document = $this->createDocument(self::MARKDOWN);
        // The document's own creation records too, and it is not what this asserts.
        $this->audit->forget();

        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'deploy-target', 1, displayedVersionNumber: 1));

        $record = $this->audit->record('review.decision.selected');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('document', $record->subject->type);
        self::assertSame((string) $document->id, $record->subject->id);
        self::assertSame([
            'documentId' => (string) $document->id,
            'decisionId' => 'deploy-target',
            'optionIndex' => 1,
            'versionNumber' => 1,
        ], $record->context);

        self::assertSame(['review.decision.selected'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    /** The label is a phrase the document's author wrote. */
    public function test_the_record_carries_the_option_index_and_not_its_label(): void
    {
        $document = $this->createDocument(self::MARKDOWN);
        $this->audit->forget();

        $selection = ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'deploy-target', 1, displayedVersionNumber: 1));

        self::assertSame('Ship straight to production', $selection->optionLabel);
        self::assertArrayNotHasKey('optionLabel', $this->audit->record('review.decision.selected')->context);
    }

    public function test_a_stale_version_records_nothing(): void
    {
        $document = $this->createDocument(self::MARKDOWN);
        $this->audit->forget();

        try {
            ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'deploy-target', 1, displayedVersionNumber: 99));
            self::fail('a stale version must be rejected');
        } catch (DomainErrors) {
        }

        self::assertSame([], $this->audit->operations());
    }

    public function test_the_handler_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(SelectDecisionOptionHandler::class);
    }

    private function createDocument(string $markdown): Document
    {
        $create = self::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $create);

        return $create(new CreateDocumentCommand($this->project, 'Deploy plan '.uniqid(), $markdown));
    }
}
