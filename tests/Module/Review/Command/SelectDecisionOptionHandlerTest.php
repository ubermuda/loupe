<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
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
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final class SelectDecisionOptionHandlerTest extends KernelTestCase
{
    private const string MARKDOWN = <<<'MD'
        Pick a target.

        <!-- decision: deploy-target -->

        - ( ) Ship to staging first
        - ( ) Ship straight to production

        <!-- /decision -->
        MD;

    private const string MULTIPLE_MARKDOWN = <<<'MD'
        Pick what ships.

        <!-- decision: ship-with -->

        - [ ] The importer
        - [x] The exporter
        - [ ] The admin page

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

        $selection = ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'deploy-target', 1, displayedVersionNumber: 1))->selection;

        self::assertNotNull($selection);
        self::assertSame('deploy-target', $selection->decisionId);
        self::assertSame(1, $selection->optionIndex);
        self::assertSame('Ship straight to production', $selection->optionLabel);
        self::assertSame(1, $selection->versionNumber);
    }

    /**
     * The unique (document, decision, option) index makes a second insert a 500
     * rather than an update, and answering is a click — a reviewer changing
     * their mind is the normal case, not an edge one.
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
        $second = ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'deploy-target', 1, displayedVersionNumber: 1))->selection;

        self::assertCount(1, $this->selections->findBy(['document' => $document]));
        self::assertNotNull($second);
        self::assertSame(1, $second->optionIndex);
        self::assertSame('Ship straight to production', $second->optionLabel);
    }

    /**
     * A multi-choice block stores one row per chosen option, so a second answer
     * adds to the first rather than replacing it.
     */
    public function test_a_multi_choice_block_records_every_chosen_option(): void
    {
        $document = $this->createDocument(self::MULTIPLE_MARKDOWN);

        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'ship-with', 0, displayedVersionNumber: 1));
        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'ship-with', 2, displayedVersionNumber: 1));

        $stored = $this->selections->findByDocumentAndDecisionId($document, 'ship-with');
        self::assertSame([0, 2], array_map(static fn (object $row): int => $row->optionIndex, $stored));
        self::assertSame(['The importer', 'The admin page'], array_map(static fn (object $row): string => $row->optionLabel, $stored));
    }

    /** Clicking a ticked checkbox unticks it, so the same POST has to take the row back off. */
    public function test_answering_a_chosen_option_again_removes_it(): void
    {
        $document = $this->createDocument(self::MULTIPLE_MARKDOWN);
        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'ship-with', 0, displayedVersionNumber: 1));
        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'ship-with', 1, displayedVersionNumber: 1));

        $result = ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'ship-with', 0, displayedVersionNumber: 1));

        self::assertNull($result->selection);
        $stored = $this->selections->findByDocumentAndDecisionId($document, 'ship-with');
        self::assertSame([1], array_map(static fn (object $row): int => $row->optionIndex, $stored));
    }

    /**
     * A reordered block resolves its stored answers by label, so the page shows
     * them on their new indexes. The click names one of those, and matching it
     * against the old index instead untick the wrong option or duplicates one.
     */
    public function test_a_reordered_multi_choice_block_toggles_the_option_the_reviewer_clicked(): void
    {
        $document = $this->createDocument(self::MULTIPLE_MARKDOWN);
        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'ship-with', 0, displayedVersionNumber: 1));

        $revise = self::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);
        $revise(new ReviseDocumentCommand(
            $document,
            "Pick what ships.\n\n<!-- decision: ship-with -->\n\n- [ ] The admin page\n- [ ] The exporter\n- [ ] The importer\n\n<!-- /decision -->\n",
            'Reversed the options.',
        ));

        // 'The importer' now sits at index 2, and index 0 is a different option.
        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'ship-with', 0, displayedVersionNumber: 2));

        $stored = $this->selections->findByDocumentAndDecisionId($document, 'ship-with');
        self::assertSame(
            [[0, 'The admin page'], [2, 'The importer']],
            array_map(static fn (object $row): array => [$row->optionIndex, $row->optionLabel], $stored),
        );

        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'ship-with', 2, displayedVersionNumber: 2));

        $stored = $this->selections->findByDocumentAndDecisionId($document, 'ship-with');
        self::assertSame(
            [[0, 'The admin page']],
            array_map(static fn (object $row): array => [$row->optionIndex, $row->optionLabel], $stored),
        );
    }

    /**
     * Two options swapping places move onto each other's stored index, so the
     * rows must not meet on the unique key while they are being brought up to
     * date.
     */
    public function test_two_answers_that_swap_places_both_survive(): void
    {
        $document = $this->createDocument(self::MULTIPLE_MARKDOWN);
        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'ship-with', 0, displayedVersionNumber: 1));
        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'ship-with', 1, displayedVersionNumber: 1));

        $revise = self::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);
        $revise(new ReviseDocumentCommand(
            $document,
            "Pick what ships.\n\n<!-- decision: ship-with -->\n\n- [ ] The exporter\n- [ ] The importer\n- [ ] The admin page\n\n<!-- /decision -->\n",
            'Swapped the first two options.',
        ));

        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'ship-with', 2, displayedVersionNumber: 2));

        $stored = $this->selections->findByDocumentAndDecisionId($document, 'ship-with');
        self::assertSame(
            [[0, 'The exporter'], [1, 'The importer'], [2, 'The admin page']],
            array_map(static fn (object $row): array => [$row->optionIndex, $row->optionLabel], $stored),
        );
    }

    /**
     * A revision that drops one chosen option and moves another onto its index.
     * The dropped answer still reports the label it was given, and the surviving
     * one has to be able to take the index it now needs.
     */
    public function test_a_dropped_option_does_not_block_the_answer_that_moves_onto_its_index(): void
    {
        $document = $this->createDocument(self::MULTIPLE_MARKDOWN);
        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'ship-with', 0, displayedVersionNumber: 1));
        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'ship-with', 1, displayedVersionNumber: 1));

        $revise = self::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);
        $revise(new ReviseDocumentCommand(
            $document,
            "Pick what ships.\n\n<!-- decision: ship-with -->\n\n- [ ] The exporter\n\n<!-- /decision -->\n",
            'Dropped the importer and the admin page.',
        ));

        // 'The exporter' is index 0 now, and the reviewer unticks it.
        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'ship-with', 0, displayedVersionNumber: 2));

        $stored = $this->selections->findByDocumentAndDecisionId($document, 'ship-with');
        self::assertSame(['The importer'], array_map(static fn (object $row): string => $row->optionLabel, $stored));
    }

    /**
     * A block may offer the same text twice, and two answers to two such
     * options both resolve onto the first of them. They have to stay on two
     * options, or one answer lands on the other's row and the click fails.
     */
    public function test_two_answers_reading_the_same_stay_on_two_options(): void
    {
        $document = $this->createDocument(
            "Pick what ships.\n\n<!-- decision: ship-with -->\n\n- [ ] Ship it\n- [ ] Ship it\n- [ ] Wait\n\n<!-- /decision -->\n",
        );
        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'ship-with', 0, displayedVersionNumber: 1));
        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'ship-with', 1, displayedVersionNumber: 1));

        $revise = self::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);
        $revise(new ReviseDocumentCommand(
            $document,
            "Pick what ships.\n\n<!-- decision: ship-with -->\n\n- [ ] Hold\n- [ ] Ship it\n- [ ] Ship it\n- [ ] Wait\n\n<!-- /decision -->\n",
            'Added a first option.',
        ));

        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'ship-with', 3, displayedVersionNumber: 2));

        $stored = $this->selections->findByDocumentAndDecisionId($document, 'ship-with');
        self::assertSame(
            [[1, 'Ship it'], [2, 'Ship it'], [3, 'Wait']],
            array_map(static fn (object $row): array => [$row->optionIndex, $row->optionLabel], $stored),
        );
    }

    /**
     * A revision can turn a multi-choice block back into a single-choice one,
     * and the block then takes one answer. The extra rows have to go, or the
     * page shows several answers a radio group cannot express.
     */
    public function test_a_block_that_becomes_single_choice_keeps_one_answer(): void
    {
        $document = $this->createDocument(self::MULTIPLE_MARKDOWN);
        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'ship-with', 0, displayedVersionNumber: 1));
        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'ship-with', 1, displayedVersionNumber: 1));

        $revise = self::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);
        $revise(new ReviseDocumentCommand(
            $document,
            str_replace(['- [ ] ', '- [x] '], '- ( ) ', self::MULTIPLE_MARKDOWN),
            'Made the block take one answer.',
        ));

        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'ship-with', 2, displayedVersionNumber: 2));

        $stored = $this->selections->findByDocumentAndDecisionId($document, 'ship-with');
        self::assertCount(1, $stored);
        self::assertSame(2, $stored[0]->optionIndex);
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

        $stored = $this->selections->findByDocumentAndDecisionId($document, 'deploy-target');
        self::assertCount(1, $stored);
        $selection = $stored[0];
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
            "Pick a target.\n\n<!-- decision: deploy-target -->\n\n- ( ) Ship straight to production\n- ( ) Ship to staging first\n\n<!-- /decision -->\n",
            'Reordered the options.',
        ));

        $this->expectException(DomainErrors::class);

        try {
            ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'deploy-target', 1, displayedVersionNumber: 1));
        } finally {
            // Names the label so a regression reports WHICH answer it invented:
            // index 1 was 'Ship straight to production' on the version the
            // reviewer read, and is 'Ship to staging first' on the current one.
            $persisted = $this->selections->findByDocumentAndDecisionId($document, 'deploy-target');
            self::assertSame([], $persisted, 'recorded an answer the reviewer never gave: '.($persisted[0]->optionLabel ?? ''));
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

        $record = $this->audit->record('review.decision_selected');
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

        self::assertSame(['review.decision_selected'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    /** The label is a phrase the document's author wrote. */
    public function test_the_record_carries_the_option_index_and_not_its_label(): void
    {
        $document = $this->createDocument(self::MARKDOWN);
        $this->audit->forget();

        $selection = ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'deploy-target', 1, displayedVersionNumber: 1))->selection;

        self::assertSame('Ship straight to production', $selection?->optionLabel);
        self::assertArrayNotHasKey('optionLabel', $this->audit->record('review.decision_selected')->context);
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
