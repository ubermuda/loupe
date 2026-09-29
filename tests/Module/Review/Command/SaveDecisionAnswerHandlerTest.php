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
use App\Module\Review\Command\SaveDecisionAnswerCommand;
use App\Module\Review\Command\SaveDecisionAnswerHandler;
use App\Module\Review\Command\SaveDecisionAnswerResult;
use App\Module\Review\Entity\DecisionAnswer;
use App\Module\Review\Entity\DecisionSelection;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DecisionAnswerRepository;
use App\Module\Review\Repository\DecisionSelectionRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Service\DecisionBlockService;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class SaveDecisionAnswerHandlerTest extends KernelTestCase
{
    private const string MARKDOWN = "<!-- decision: features -->\n\n- [ ] Import\n- [ ] Export\n- [ ] Search\n\n<!-- /decision -->\n\n<!-- decision: target -->\n\n- ( ) Staging\n- ( ) Production\n\n<!-- /decision -->";

    private User $owner;
    private Document $document;
    private SaveDecisionAnswerHandler $save;
    private EntityManagerInterface $em;
    private DecisionSelectionRepository $selections;
    private DecisionAnswerRepository $answers;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->audit = RecordingAuditor::installedIn($container);
        $this->em = $container->get(EntityManagerInterface::class);
        $this->owner = new User('Decision reviewer', 'save-decision-answer@example.test', 'hashed');
        $project = new Project($this->owner, 'decisions');
        $this->em->persist($this->owner);
        $this->em->persist($project);
        $this->em->flush();
        $this->document = ($container->get(CreateDocumentHandler::class))(new CreateDocumentCommand($project, 'Decisions', self::MARKDOWN));
        $this->selections = $container->get(DecisionSelectionRepository::class);
        $this->answers = $container->get(DecisionAnswerRepository::class);
        $this->save = new SaveDecisionAnswerHandler(
            $container->get(DocumentVersionRepository::class),
            $this->selections,
            $this->answers,
            $container->get(DecisionBlockService::class),
            $this->em,
            $this->audit->auditor,
            $container->get(EventDispatcherInterface::class),
        );
        $this->audit->forget();
    }

    public function test_saves_a_single_choice(): void
    {
        $result = $this->answer('target', [1]);

        self::assertTrue($result->changed);
        self::assertFalse($result->cleared);
        self::assertSame([[1, 'Production', 1]], $this->stored('target'));
        $answer = $this->answerRow('target');
        self::assertNull($answer->note);
        self::assertSame($this->owner, $answer->answeredBy);
        self::assertSame(1, $answer->answeredAtVersion);
    }

    public function test_changes_a_saved_choice(): void
    {
        $this->answer('target', [0]);
        $this->answer('target', [1]);

        self::assertSame([[1, 'Production', 1]], $this->stored('target'));
    }

    public function test_saves_several_options_of_a_multiple_choice(): void
    {
        $this->answer('features', [2, 0, 2]);

        self::assertSame([[0, 'Import', 1], [2, 'Search', 1]], $this->stored('features'));
    }

    public function test_saves_a_note_without_a_pick(): void
    {
        $result = $this->answer('target', [], '  Neither, wait a week.  ');

        self::assertFalse($result->cleared);
        self::assertSame([], $this->stored('target'));
        self::assertSame('Neither, wait a week.', $this->answerRow('target')->note);
    }

    public function test_saves_a_pick_with_a_note(): void
    {
        $this->answer('target', [0], 'Staging first.');

        self::assertSame([[0, 'Staging', 1]], $this->stored('target'));
        self::assertSame('Staging first.', $this->answerRow('target')->note);
    }

    public function test_a_blank_note_is_stored_as_null(): void
    {
        $this->answer('target', [0], 'Staging first.');
        $this->answer('target', [0], "  \n ");

        self::assertNull($this->answerRow('target')->note);
    }

    public function test_no_pick_and_no_note_leaves_the_decision_unanswered(): void
    {
        $this->answer('features', [0], 'Import only.');
        $result = $this->answer('features', [], '');

        self::assertTrue($result->cleared);
        self::assertSame([], $this->stored('features'));
        self::assertNull($this->answers->findOneByDocumentAndDecisionId($this->document, 'features'));
    }

    public function test_clear_removes_the_picks_and_keeps_the_note(): void
    {
        $this->answer('features', [0, 1], 'Both.');
        $result = $this->answer('features', [2], 'Both.', clear: true);

        self::assertTrue($result->cleared);
        self::assertSame([], $this->stored('features'));
        self::assertSame('Both.', $this->answerRow('features')->note);
        self::assertSame(['review.decision_saved', 'review.decision_cleared'], $this->audit->operations());
    }

    public function test_clear_with_no_note_leaves_the_decision_unanswered(): void
    {
        $this->answer('features', [0, 1]);
        $result = $this->answer('features', [2], null, clear: true);

        self::assertTrue($result->cleared);
        self::assertSame([], $this->stored('features'));
        self::assertNull($this->answers->findOneByDocumentAndDecisionId($this->document, 'features'));
    }

    /** An answer given on an older tab lands on the options as the latest version orders them. */
    public function test_an_answer_saved_from_an_older_version_carries_forward_by_label(): void
    {
        $this->revise(str_replace("- ( ) Staging\n- ( ) Production", "- ( ) Production\n- ( ) Canary\n- ( ) Staging", self::MARKDOWN));

        $this->answer('target', [0], 'From the old tab.', version: 1);

        self::assertSame([[2, 'Staging', 2]], $this->stored('target'));
        self::assertSame(2, $this->answerRow('target')->answeredAtVersion);
    }

    public function test_an_option_the_latest_version_dropped_is_refused(): void
    {
        $this->revise(str_replace('- ( ) Staging', '- ( ) Canary', self::MARKDOWN));

        $this->assertRefused(['optionIndexes' => 'review.decision.error.unknown_option'], 'target', [0], version: 1);
    }

    public function test_a_decision_the_latest_version_dropped_is_refused(): void
    {
        $this->revise("Nothing to decide.\n");

        $this->assertRefused(['decisionId' => 'review.decision.error.unknown'], 'target', [0], version: 1);
    }

    public function test_an_unknown_version_is_refused(): void
    {
        $this->assertRefused(['versionNumber' => 'review.decision.error.unknown_version'], 'target', [0], version: 7);
    }

    public function test_an_unknown_decision_is_refused(): void
    {
        $this->assertRefused(['decisionId' => 'review.decision.error.unknown'], 'missing', [0]);
    }

    public function test_an_unknown_option_is_refused(): void
    {
        $this->assertRefused(['optionIndexes' => 'review.decision.error.unknown_option'], 'features', [1, 9]);
    }

    public function test_two_options_on_a_single_choice_are_refused(): void
    {
        $this->assertRefused(['optionIndexes' => 'review.decision.error.unknown_option'], 'target', [0, 1]);
    }

    /** A single-choice block in the latest version takes one option, whatever the older tab offered. */
    public function test_two_options_on_a_block_that_became_single_choice_are_refused(): void
    {
        $this->revise(str_replace(['- [ ] Import', '- [ ] Export', '- [ ] Search'], ['- ( ) Import', '- ( ) Export', '- ( ) Search'], self::MARKDOWN));

        $this->assertRefused(['optionIndexes' => 'review.decision.error.unknown_option'], 'features', [0, 1], version: 1);
    }

    /** The last write wins: an answer from a tab that never saw the stored one replaces it. */
    public function test_a_second_tab_overwrites_the_saved_answer(): void
    {
        $this->answer('features', [0], 'First tab.');
        $this->answer('features', [2], 'Second tab.');

        self::assertSame([[2, 'Search', 1]], $this->stored('features'));
        self::assertSame('Second tab.', $this->answerRow('features')->note);
    }

    public function test_a_later_save_moves_the_answer_time_and_the_answerer(): void
    {
        $this->answer('target', [0]);
        $answer = $this->answerRow('target');
        $answer->updatedAt = new \DateTimeImmutable('-1 day');
        $answer->answeredBy = null;
        $this->em->flush();

        $this->answer('target', [0], 'Now with a note.');

        self::assertSame($this->owner, $answer->answeredBy);
        self::assertGreaterThan(new \DateTimeImmutable('-1 hour'), $answer->updatedAt);
    }

    public function test_a_repeat_writes_nothing_and_records_no_audit(): void
    {
        $this->answer('features', [0, 2], 'Both.');
        $rows = $this->selections->findByDocumentAndDecisionId($this->document, 'features');
        $updatedAt = $this->answerRow('features')->updatedAt;

        $result = $this->answer('features', [2, 0], ' Both. ');

        self::assertFalse($result->changed);
        self::assertSame($rows, $this->selections->findByDocumentAndDecisionId($this->document, 'features'));
        self::assertSame($updatedAt, $this->answerRow('features')->updatedAt);
        self::assertSame(['review.decision_saved'], $this->audit->operations());
    }

    /** Picks saved before notes existed have no answer row; saving them again creates it. */
    public function test_saving_picks_that_have_no_answer_row_creates_one(): void
    {
        $this->em->persist(new DecisionSelection($this->document, 'target', 1, 'Production', 1));
        $this->em->flush();

        $result = $this->answer('target', [1]);

        self::assertTrue($result->changed);
        self::assertSame(1, $this->answerRow('target')->answeredAtVersion);
    }

    public function test_an_audit_record_counts_the_options_and_keeps_the_note_out(): void
    {
        $this->answer('features', [0, 1], 'A private thought.');

        $record = $this->audit->record('review.decision_saved');
        self::assertSame('features', $record->context['decisionId']);
        self::assertSame(2, $record->context['optionCount']);
        self::assertTrue($record->context['hasNote']);
        self::assertStringNotContainsString('private', json_encode($record->context, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<int> $indexes
     */
    private function answer(string $decisionId, array $indexes, ?string $note = null, bool $clear = false, int $version = 1): SaveDecisionAnswerResult
    {
        return ($this->save)(new SaveDecisionAnswerCommand($this->document, $decisionId, $version, $indexes, $note, $clear, $this->owner));
    }

    /**
     * @param array<string, string> $errors
     * @param list<int>             $indexes
     */
    private function assertRefused(array $errors, string $decisionId, array $indexes, int $version = 1): void
    {
        try {
            $this->answer($decisionId, $indexes, 'A note.', version: $version);
            self::fail('The answer must be refused.');
        } catch (DomainErrors $refusal) {
            self::assertSame($errors, $refusal->errors);
        }
        self::assertTrue($this->em->isOpen());
        self::assertSame([], $this->stored($decisionId));
        self::assertNull($this->answers->findOneByDocumentAndDecisionId($this->document, $decisionId));
        self::assertSame([], $this->audit->operations());
    }

    private function revise(string $markdown): void
    {
        (self::getContainer()->get(ReviseDocumentHandler::class))(new ReviseDocumentCommand($this->document, $markdown, 'Revised.'));
        $this->audit->forget();
    }

    /** @return list<array{int, string, int}> each the index, the label and the version */
    private function stored(string $decisionId): array
    {
        return array_map(
            static fn (DecisionSelection $selection): array => [$selection->optionIndex, $selection->optionLabel, $selection->versionNumber],
            $this->selections->findByDocumentAndDecisionId($this->document, $decisionId),
        );
    }

    private function answerRow(string $decisionId): DecisionAnswer
    {
        return $this->answers->findOneByDocumentAndDecisionId($this->document, $decisionId)
            ?? throw new \LogicException('the decision has no answer row');
    }
}
