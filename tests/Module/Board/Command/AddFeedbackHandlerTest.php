<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\AddFeedbackCommand;
use App\Module\Board\Command\AddFeedbackHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Command\NewAnchor;
use App\Module\SiteReview\Command\NewStroke;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\RecordingAuditor;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;
use Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class AddFeedbackHandlerTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private AddFeedbackHandler $handler;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $handler = self::getContainer()->get(AddFeedbackHandler::class);
        self::assertInstanceOf(AddFeedbackHandler::class, $handler);
        $this->handler = $handler;

        $this->setBoardEnabled(true);
    }

    public function test_a_note_creates_its_card_and_the_link_says_so(): void
    {
        $project = $this->project('feedback-new');
        $body = "\n   \n  ".str_repeat('Footer overlaps the launcher ', 5)."\nSeen at 1280px.";

        $link = ($this->handler)($this->command($project, $body));

        self::assertTrue($link->createdCard);
        $card = $link->card;
        self::assertSame('backlog', $card->column->slug);
        self::assertSame(80, mb_strlen($card->title));
        self::assertStringStartsWith('Footer overlaps the launcher Footer', $card->title);
        self::assertSame('', $card->body);
        self::assertSame(CardType::SiteReview, $card->type);
        self::assertSame(CardReporter::Reviewer, $card->reporter);
        self::assertNull($card->parent);
        self::assertSame(trim($body), $link->comment->body);
        self::assertCount(1, $link->comment->anchors);
        self::assertSame(1, $this->rows('board_cards', $project));
        self::assertSame(1, $this->rows('site_review_comments', $project));
        self::assertContains('board.feedback_added', $this->audit->operations());
    }

    public function test_a_note_lands_on_an_open_card_of_the_project(): void
    {
        $project = $this->project('feedback-existing');
        $card = $this->card($project, 'backlog');

        $link = ($this->handler)($this->command($project, 'Too much padding', cardId: (string) $card->id));

        self::assertFalse($link->createdCard);
        self::assertSame((string) $card->id, (string) $link->card->id);
        self::assertSame(1, $this->rows('board_cards', $project));
        self::assertSame(1, $this->rows('board_card_site_review_comments', $project));
    }

    public function test_a_note_creates_a_child_card_of_an_epic(): void
    {
        $project = $this->project('feedback-epic');
        $epic = $this->card($project, 'backlog', CardType::Epic);

        $link = ($this->handler)($this->command($project, 'Checkout button is grey', parentCardId: (string) $epic->id));

        self::assertTrue($link->createdCard);
        self::assertSame((string) $epic->id, (string) $link->card->parent?->id);
        self::assertSame(CardType::SiteReview, $link->card->type);
    }

    public function test_a_card_of_another_project_reads_as_not_found(): void
    {
        $project = $this->project('feedback-foreign');
        $foreign = $this->card($this->project('feedback-foreign-other'), 'backlog');

        $this->assertRefused('target', AddFeedbackHandler::TARGET_NOT_FOUND, $project, cardId: (string) $foreign->id);
        $this->assertRefused('target', AddFeedbackHandler::TARGET_NOT_FOUND, $project, cardId: (string) Uuid::v7());
        $this->assertRefused('target', AddFeedbackHandler::TARGET_NOT_FOUND, $project, parentCardId: (string) $foreign->id);
    }

    public function test_a_card_in_a_terminal_column_is_closed(): void
    {
        $project = $this->project('feedback-closed');
        $done = $this->card($project, 'done');

        $this->assertRefused('target', AddFeedbackHandler::TARGET_CLOSED, $project, cardId: (string) $done->id);
    }

    public function test_a_parent_that_is_not_an_epic_is_refused(): void
    {
        $project = $this->project('feedback-not-epic');
        $feature = $this->card($project, 'backlog');

        $this->assertRefused('target', AddFeedbackHandler::TARGET_NOT_EPIC, $project, parentCardId: (string) $feature->id);
    }

    public function test_an_epic_in_a_terminal_column_is_closed(): void
    {
        $project = $this->project('feedback-closed-epic');
        $epic = $this->card($project, 'done', CardType::Epic);

        $this->assertRefused('target', AddFeedbackHandler::TARGET_CLOSED, $project, parentCardId: (string) $epic->id);
    }

    public function test_a_retry_after_the_board_went_off_returns_the_saved_note(): void
    {
        $project = $this->project('feedback-retry-board-off');
        $command = $this->command($project, 'Saved before the switch', deliveryId: (string) Uuid::v4());
        $first = ($this->handler)($command);
        $this->setBoardEnabled(false);

        $retried = ($this->handler)($command);

        self::assertSame((string) $first->id, (string) $retried->id);
        $this->assertRefused('board', AddFeedbackHandler::BOARD_DISABLED, $project, deliveryId: (string) Uuid::v4());
    }

    public function test_nothing_is_written_while_the_board_is_off(): void
    {
        $project = $this->project('feedback-board-off');
        $this->setBoardEnabled(false);

        $this->assertRefused('board', AddFeedbackHandler::BOARD_DISABLED, $project);
    }

    public function test_a_retried_delivery_returns_the_same_card_and_creates_no_second_one(): void
    {
        $project = $this->project('feedback-retry');
        $command = $this->command($project, 'One capture', deliveryId: (string) Uuid::v4());

        $first = ($this->handler)($command);
        $this->audit->forget();
        $second = ($this->handler)($command);

        self::assertSame((string) $first->id, (string) $second->id);
        self::assertSame((string) $first->card->id, (string) $second->card->id);
        self::assertSame(1, $this->rows('board_cards', $project));
        self::assertSame(1, $this->rows('site_review_comments', $project));
        self::assertSame([], $this->audit->operations());
    }

    public function test_a_delivery_id_reused_for_another_note_is_a_conflict(): void
    {
        $project = $this->project('feedback-conflict');
        $deliveryId = (string) Uuid::v4();
        ($this->handler)($this->command($project, 'First words', deliveryId: $deliveryId));

        $this->assertRefused('deliveryId', 'delivery_conflict', $project, body: 'Other words', deliveryId: $deliveryId);
        self::assertSame(1, $this->rows('board_cards', $project));
    }

    /**
     * A stroke point of NAN cannot be encoded as JSON, so the comment's flush
     * fails after the card exists. Both must roll back together.
     */
    public function test_a_comment_that_fails_to_save_takes_its_new_card_with_it(): void
    {
        $project = $this->project('feedback-rollback');
        $projectId = (string) $project->id;
        $command = new AddFeedbackCommand(
            project: $project,
            body: 'Card title',
            url: 'https://preview.example/checkout',
            strokes: [new NewStroke('page', [[\NAN, 0.0]])],
        );

        $failure = null;
        try {
            ($this->handler)($command);
        } catch (ConversionException $e) {
            $failure = $e;
        }

        self::assertNotNull($failure, 'The comment flush should have failed.');
        $connection = $this->em->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM board_cards WHERE project_id = ?', [$projectId]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM site_review_comments WHERE project_id = ?', [$projectId]));
    }

    private function assertRefused(string $field, string $code, Project $project, string $body = 'A note', ?string $cardId = null, ?string $parentCardId = null, ?string $deliveryId = null): void
    {
        $cards = $this->rows('board_cards', $project);
        $comments = $this->rows('site_review_comments', $project);

        try {
            ($this->handler)($this->command($project, $body, $cardId, $parentCardId, $deliveryId));
            self::fail('The write should have been refused.');
        } catch (DomainErrors $e) {
            self::assertSame([$field => $code], $e->errors);
        }

        self::assertTrue($this->em->isOpen(), 'A refusal must not close the EntityManager.');
        self::assertSame($cards, $this->rows('board_cards', $project));
        self::assertSame($comments, $this->rows('site_review_comments', $project));
    }

    private function command(Project $project, string $body, ?string $cardId = null, ?string $parentCardId = null, ?string $deliveryId = null): AddFeedbackCommand
    {
        return new AddFeedbackCommand(
            project: $project,
            body: trim($body) ?: throw new \LogicException('blank body'),
            url: 'https://preview.example/checkout',
            anchors: [new NewAnchor('.footer', 'Footer')],
            deliveryId: $deliveryId,
            cardId: $cardId,
            parentCardId: $parentCardId,
        );
    }

    private function rows(string $table, Project $project): int
    {
        $sql = 'board_card_site_review_comments' === $table
            ? 'SELECT COUNT(*) FROM board_card_site_review_comments l JOIN board_cards c ON c.id = l.card_id WHERE c.project_id = ?'
            : \sprintf('SELECT COUNT(*) FROM %s WHERE project_id = ?', $table);

        return (int) $this->em->getConnection()->fetchOne($sql, [(string) $project->id]);
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
