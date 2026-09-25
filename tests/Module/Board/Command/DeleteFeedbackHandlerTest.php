<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\AddFeedbackCommand;
use App\Module\Board\Command\AddFeedbackHandler;
use App\Module\Board\Command\DeleteCardCommand;
use App\Module\Board\Command\DeleteCardHandler;
use App\Module\Board\Command\DeleteFeedbackCommand;
use App\Module\Board\Command\DeleteFeedbackHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\SiteReview\Command\CommentNotFound;
use App\Module\SiteReview\Command\NewAnchor;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;
use Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class DeleteFeedbackHandlerTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private DeleteFeedbackHandler $handler;
    private AddFeedbackHandler $add;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $handler = self::getContainer()->get(DeleteFeedbackHandler::class);
        self::assertInstanceOf(DeleteFeedbackHandler::class, $handler);
        $this->handler = $handler;

        $add = self::getContainer()->get(AddFeedbackHandler::class);
        self::assertInstanceOf(AddFeedbackHandler::class, $add);
        $this->add = $add;

        $this->setBoardEnabled(true);
    }

    public function test_the_note_that_created_its_card_takes_the_card_with_it(): void
    {
        $project = $this->project('delete-feedback-both');
        $link = $this->addNote($project);

        self::assertTrue(($this->handler)($this->command($project, $link)));

        self::assertSame(0, $this->rows('board_cards', $project));
        self::assertSame(0, $this->rows('site_review_comments', $project));
        self::assertSame(0, $this->anchorRows($project));
        self::assertSame('reviewer', $this->audit->record('board.card_deleted')->context['actor']);
        self::assertContains('board.feedback_deleted', $this->audit->operations());
    }

    /** The epic listener flushes after the delete, which a stale link would break. */
    public function test_a_created_child_card_of_an_epic_goes_too(): void
    {
        $project = $this->project('delete-feedback-child');
        $epic = $this->card($project, 'backlog', CardType::Epic);
        $link = $this->addNote($project, parentCardId: (string) $epic->id);

        self::assertTrue(($this->handler)($this->command($project, $link)));

        self::assertSame(1, $this->rows('board_cards', $project));
        self::assertSame(0, $this->rows('site_review_comments', $project));
    }

    public function test_a_note_on_a_card_that_existed_leaves_the_card(): void
    {
        $project = $this->project('delete-feedback-existing');
        $card = $this->card($project, 'backlog');
        $link = $this->addNote($project, cardId: (string) $card->id);

        self::assertFalse(($this->handler)($this->command($project, $link)));

        self::assertSame(1, $this->rows('board_cards', $project));
        self::assertSame(0, $this->rows('site_review_comments', $project));
    }

    public function test_a_created_card_moved_out_of_the_default_column_stays(): void
    {
        $project = $this->project('delete-feedback-moved');
        $link = $this->addNote($project);
        $this->em->getConnection()->executeStatement(
            'UPDATE board_cards SET column_id = ? WHERE id = ?',
            [(string) $this->column($project, 'next')->id, (string) $link->card->id],
        );

        self::assertFalse(($this->handler)($this->command($project, $link)));

        self::assertSame(1, $this->rows('board_cards', $project));
        self::assertSame(0, $this->rows('site_review_comments', $project));
    }

    public function test_a_created_card_that_holds_another_note_stays(): void
    {
        $project = $this->project('delete-feedback-shared');
        $link = $this->addNote($project);
        $this->addNote($project, cardId: (string) $link->card->id, body: 'A second note');

        self::assertFalse(($this->handler)($this->command($project, $link)));

        self::assertSame(1, $this->rows('board_cards', $project));
        self::assertSame(1, $this->rows('site_review_comments', $project));
        self::assertSame(1, $this->rows('board_card_site_review_comments', $project));
    }

    public function test_a_created_card_that_became_an_epic_stays(): void
    {
        $project = $this->project('delete-feedback-epic');
        $link = $this->addNote($project);
        $this->em->getConnection()->executeStatement(
            'UPDATE board_cards SET type = ? WHERE id = ?',
            [CardType::Epic->value, (string) $link->card->id],
        );

        self::assertFalse(($this->handler)($this->command($project, $link)));

        self::assertSame(1, $this->rows('board_cards', $project));
        self::assertSame(0, $this->rows('site_review_comments', $project));
    }

    public function test_a_created_card_someone_wrote_a_body_for_stays(): void
    {
        $project = $this->project('delete-feedback-body');
        $link = $this->addNote($project);
        $this->em->getConnection()->executeStatement(
            'UPDATE board_cards SET body = ? WHERE id = ?',
            ['Repro: resize to 1280px.', (string) $link->card->id],
        );

        $this->assertCardStays($project, $link);
    }

    public function test_a_created_card_with_a_pull_request_stays(): void
    {
        $project = $this->project('delete-feedback-pull-request');
        $link = $this->addNote($project);
        $this->em->persist(new CardPullRequest($link->card, 'https://example.com/pr/1'));
        $this->em->flush();

        $this->assertCardStays($project, $link);
    }

    public function test_a_created_card_with_a_document_stays(): void
    {
        $project = $this->project('delete-feedback-document');
        $link = $this->addNote($project);
        $this->em->persist($document = new Document($project->owner, $project, 'The design'));
        $this->em->persist(new CardDocument($link->card, $document));
        $this->em->flush();

        $this->assertCardStays($project, $link);
    }

    public function test_a_created_card_that_links_to_another_card_stays(): void
    {
        $project = $this->project('delete-feedback-link-out');
        $other = $this->card($project, 'backlog');
        $link = $this->addNote($project);
        $this->em->persist(new CardLink($link->card, $other, CardLinkKind::Blocks));
        $this->em->flush();

        $this->assertCardStays($project, $link, cards: 2);
    }

    public function test_a_created_card_that_another_card_links_to_stays(): void
    {
        $project = $this->project('delete-feedback-link-in');
        $other = $this->card($project, 'backlog');
        $link = $this->addNote($project);
        $this->em->persist(new CardLink($other, $link->card, CardLinkKind::RelatesTo));
        $this->em->flush();

        $this->assertCardStays($project, $link, cards: 2);
    }

    public function test_a_note_saved_with_no_card_is_deleted_alone(): void
    {
        $project = $this->project('delete-feedback-unlinked');
        $link = $this->addNote($project);
        $this->em->getConnection()->executeStatement('DELETE FROM board_card_site_review_comments WHERE id = ?', [(string) $link->id]);

        self::assertFalse(($this->handler)($this->command($project, $link)));

        self::assertSame(1, $this->rows('board_cards', $project));
        self::assertSame(0, $this->rows('site_review_comments', $project));
    }

    public function test_an_addressed_note_is_not_found_and_nothing_changes(): void
    {
        $project = $this->project('delete-feedback-addressed');
        $link = $this->addNote($project);
        $link->comment->status = SiteReviewCommentStatus::Addressed;
        $this->em->flush();

        $this->assertNotFound($project, $link->comment->id);

        self::assertSame(1, $this->rows('board_cards', $project));
        self::assertSame(1, $this->rows('site_review_comments', $project));
    }

    public function test_a_note_of_another_project_is_not_found(): void
    {
        $project = $this->project('delete-feedback-mine');
        $foreign = $this->project('delete-feedback-foreign');
        $link = $this->addNote($foreign);

        $this->assertNotFound($project, $link->comment->id);
        $this->assertNotFound($project, Uuid::v7());

        self::assertSame(1, $this->rows('board_cards', $foreign));
        self::assertSame(1, $this->rows('site_review_comments', $foreign));
    }

    public function test_nothing_is_deleted_while_the_board_is_off(): void
    {
        $project = $this->project('delete-feedback-off');
        $link = $this->addNote($project);
        $this->setBoardEnabled(false);

        try {
            ($this->handler)($this->command($project, $link));
            self::fail('The delete should have been refused.');
        } catch (DomainErrors $e) {
            self::assertSame(['board' => DeleteFeedbackHandler::BOARD_DISABLED], $e->errors);
        }

        self::assertSame(1, $this->rows('board_cards', $project));
        self::assertSame(1, $this->rows('site_review_comments', $project));
    }

    public function test_deleting_a_card_deletes_its_comments_and_their_anchors(): void
    {
        $project = $this->project('delete-card-comments');
        $card = $this->card($project, 'backlog');
        $this->addNote($project, cardId: (string) $card->id);
        $addressed = $this->addNote($project, cardId: (string) $card->id, body: 'Another note');
        $addressed->comment->status = SiteReviewCommentStatus::Addressed;
        $this->em->flush();
        self::assertSame(2, $this->anchorRows($project));

        $delete = self::getContainer()->get(DeleteCardHandler::class);
        self::assertInstanceOf(DeleteCardHandler::class, $delete);
        $delete(new DeleteCardCommand($card, CardReporter::Human));
        // A later write in the same request flushes every managed link.
        $this->card($project, 'backlog');

        self::assertSame(1, $this->rows('board_cards', $project));
        self::assertSame(0, $this->rows('site_review_comments', $project));
        self::assertSame(0, $this->anchorRows($project));
    }

    private function assertNotFound(Project $project, ?Uuid $commentId): void
    {
        self::assertNotNull($commentId);
        try {
            ($this->handler)(new DeleteFeedbackCommand($project, $commentId));
            self::fail('The delete should have been refused.');
        } catch (CommentNotFound) {
        }

        self::assertTrue($this->em->isOpen(), 'A refusal must not close the EntityManager.');
    }

    private function assertCardStays(Project $project, CardSiteReviewComment $link, int $cards = 1): void
    {
        self::assertFalse(($this->handler)($this->command($project, $link)));

        self::assertSame($cards, $this->rows('board_cards', $project));
        self::assertSame(0, $this->rows('site_review_comments', $project));
    }

    private function command(Project $project, CardSiteReviewComment $link): DeleteFeedbackCommand
    {
        return new DeleteFeedbackCommand($project, $link->comment->id ?? throw new \LogicException('unsaved comment'));
    }

    private function addNote(Project $project, ?string $cardId = null, ?string $parentCardId = null, string $body = 'Footer overlaps the launcher'): CardSiteReviewComment
    {
        return ($this->add)(new AddFeedbackCommand(
            project: $project,
            body: trim($body) ?: throw new \LogicException('blank body'),
            url: 'https://preview.example/checkout',
            anchors: [new NewAnchor('.footer', 'Footer')],
            cardId: $cardId,
            parentCardId: $parentCardId,
        ));
    }

    private function rows(string $table, Project $project): int
    {
        $sql = 'board_card_site_review_comments' === $table
            ? 'SELECT COUNT(*) FROM board_card_site_review_comments l JOIN board_cards c ON c.id = l.card_id WHERE c.project_id = ?'
            : \sprintf('SELECT COUNT(*) FROM %s WHERE project_id = ?', $table);

        return (int) $this->em->getConnection()->fetchOne($sql, [(string) $project->id]);
    }

    private function anchorRows(Project $project): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM site_review_comment_anchors a JOIN site_review_comments c ON c.id = a.comment_id WHERE c.project_id = ?',
            [(string) $project->id],
        );
    }

    private function project(string $label): Project
    {
        $owner = new User(fullName: 'Riley', email: $label.'-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, $label);
        $this->em->persist($project);
        $this->seedColumns($project);
        $this->em->flush();

        return $project;
    }

    private static int $number = 0;

    private function card(Project $project, string $slug, CardType $type = CardType::Feature): Card
    {
        $card = new Card($project, $this->column($project, $slug), 'Existing card', '', ++self::$number, $type);
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }

    private function setBoardEnabled(bool $enabled): void
    {
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[BoardInstallFlags::FLAG_BOARD_ENABLED]->value = $enabled;
        $this->em->flush();

        $reader = self::getContainer()->get(FeatureFlagReaderInterface::class);
        self::assertInstanceOf(ResetInterface::class, $reader);
        $reader->reset();
    }
}
