<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Command\ConfigureBoardColumnCommand;
use App\Module\Board\Command\ConfigureBoardColumnHandler;
use App\Module\Board\Command\SetBoardColumnTerminalCommand;
use App\Module\Board\Command\SetBoardColumnTerminalHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Event\BoardColumnTerminalChanged;
use App\Module\Board\EventListener\ResolveFeedbackOnBoardColumnTerminalChanged;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Board\Service\CardFeedbackResolver;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Command\ResolveSiteReviewCommentHandler;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Tests\Support\RecordingAuditor;
use App\Tests\Support\RecordingLogger;
use Doctrine\DBAL\Exception\InvalidArgumentException as DbalInvalidArgument;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Driven through a real flag change, so the listener runs where both column handlers dispatch. */
final class ResolveFeedbackOnBoardColumnTerminalChangedTest extends KernelTestCase
{
    use ResolveFeedbackScenario;

    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_a_column_that_turns_terminal_resolves_the_feedback_of_its_cards(): void
    {
        $project = $this->feedbackProject('feedback-terminal-on');
        $first = $this->feedback($this->cardIn($project, 'in-progress', 1), SiteReviewCommentStatus::Pending);
        $second = $this->feedback($this->cardIn($project, 'in-progress', 2), SiteReviewCommentStatus::Addressed);
        $elsewhere = $this->feedback($this->cardIn($project, 'next', 3), SiteReviewCommentStatus::Pending);
        $this->em->flush();

        $this->setTerminal($project, 'in-progress', true);

        self::assertSame(SiteReviewCommentStatus::Resolved, $this->statusOf($first));
        self::assertSame(SiteReviewCommentStatus::Resolved, $this->statusOf($second));
        self::assertSame(SiteReviewCommentStatus::Pending, $this->statusOf($elsewhere));

        $records = $this->audit->records('site_review.comment_resolved');
        self::assertCount(2, $records);
        foreach ($records as $record) {
            self::assertSame('human', $record->context['actor']);
        }
    }

    public function test_a_column_that_stops_being_terminal_resolves_nothing(): void
    {
        $project = $this->feedbackProject('feedback-terminal-off');
        $card = $this->cardIn($project, 'done');
        $comment = $this->feedback($card, SiteReviewCommentStatus::Addressed);
        $this->em->flush();
        $cardId = $card->id;

        $this->setTerminal($project, 'done', false);

        self::assertSame(SiteReviewCommentStatus::Addressed, $this->statusOf($comment));
        self::assertSame([], $this->audit->records('site_review.comment_resolved'));
        $reopened = $this->em->find(Card::class, $cardId);
        self::assertInstanceOf(Card::class, $reopened);
        self::assertFalse($reopened->column->terminal);
    }

    public function test_the_column_settings_dialog_that_turns_a_column_terminal_resolves_its_feedback(): void
    {
        $project = $this->feedbackProject('feedback-configure-on');
        $comment = $this->feedback($this->cardIn($project, 'in-progress'), SiteReviewCommentStatus::Pending);
        $this->em->flush();

        $this->configureTerminal($project, 'in-progress', true);

        self::assertSame(SiteReviewCommentStatus::Resolved, $this->statusOf($comment));
        self::assertCount(1, $this->audit->records('site_review.comment_resolved'));
    }

    public function test_the_column_settings_dialog_that_clears_terminal_resolves_nothing(): void
    {
        $project = $this->feedbackProject('feedback-configure-off');
        $card = $this->cardIn($project, 'done');
        $comment = $this->feedback($card, SiteReviewCommentStatus::Addressed);
        $this->em->flush();
        $cardId = $card->id;

        $this->configureTerminal($project, 'done', false);

        self::assertSame(SiteReviewCommentStatus::Addressed, $this->statusOf($comment));
        self::assertSame([], $this->audit->records('site_review.comment_resolved'));
        $reopened = $this->em->find(Card::class, $cardId);
        self::assertInstanceOf(Card::class, $reopened);
        self::assertFalse($reopened->column->terminal);
    }

    public function test_a_database_failure_escapes_the_listener_and_other_failures_are_logged(): void
    {
        $project = $this->feedbackProject('feedback-terminal-failure');
        $resolve = self::getContainer()->get(ResolveSiteReviewCommentHandler::class);
        self::assertInstanceOf(ResolveSiteReviewCommentHandler::class, $resolve);
        $event = new BoardColumnTerminalChanged($project, 'column-id', true, ['card-id'], CardReporter::Human);

        $logger = new RecordingLogger();
        $failing = $this->createStub(CardSiteReviewCommentRepository::class);
        $failing->method('findUnresolvedForCards')->willThrowException(new \RuntimeException('boom'));
        new ResolveFeedbackOnBoardColumnTerminalChanged(new CardFeedbackResolver($failing, $resolve), $this->em, $logger)($event);
        self::assertSame(['board.feedback_resolve_failed'], array_column($logger->records, 'message'));

        $database = $this->createStub(CardSiteReviewCommentRepository::class);
        $database->method('findUnresolvedForCards')->willThrowException(new DbalInvalidArgument('database'));
        $this->expectException(DbalInvalidArgument::class);
        new ResolveFeedbackOnBoardColumnTerminalChanged(new CardFeedbackResolver($database, $resolve), $this->em, new RecordingLogger())($event);
    }

    private function setTerminal(Project $project, string $slug, bool $terminal): void
    {
        $handler = self::getContainer()->get(SetBoardColumnTerminalHandler::class);
        self::assertInstanceOf(SetBoardColumnTerminalHandler::class, $handler);

        $handler(new SetBoardColumnTerminalCommand($this->column($project, $slug), $terminal, CardReporter::Human));
    }

    private function configureTerminal(Project $project, string $slug, bool $terminal): void
    {
        $handler = self::getContainer()->get(ConfigureBoardColumnHandler::class);
        self::assertInstanceOf(ConfigureBoardColumnHandler::class, $handler);
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $column = $this->column($project, $slug);
        $defaultId = (string) $this->column($project, 'backlog')->id;
        $handler(new ConfigureBoardColumnCommand($column, CardReporter::Human, $translator->trans($column->label), false, $terminal, $column->label, $defaultId, $column->terminal));
    }
}
