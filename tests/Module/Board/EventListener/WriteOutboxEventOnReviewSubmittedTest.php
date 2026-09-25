<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\ReviewEventType;
use App\Outbox\Repository\OutboxEventRepository;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class WriteOutboxEventOnReviewSubmittedTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private User $reviewer;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[BoardInstallFlags::FLAG_BOARD_ENABLED]->value = true;

        $this->reviewer = new User(fullName: 'Riley', email: 'review-outbox-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($this->reviewer);
        $this->project = new Project($this->reviewer, 'review-outbox-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        foreach (['tech-design', 'implementation'] as $position => $slug) {
            $this->em->persist(new BoardColumn(project: $this->project, label: $slug, slug: $slug, position: 4 + $position));
        }
        $this->em->flush();
    }

    public function test_changes_requested_names_the_card_in_the_stage_column(): void
    {
        $document = $this->document(['design', 'decisions']);
        $card = $this->card('tech-design', $document);

        $this->submit($document, Verdict::ChangesRequested);

        self::assertSame([
            'type' => 'document.review_submitted',
            'subject' => ['type' => 'document', 'id' => (string) $document->id],
            'projectId' => (string) $this->project->id,
            'verdict' => 'changes-requested',
            'cardIds' => [(string) $card->id],
            'actor' => 'human',
            'cardId' => (string) $card->id,
            'cardNumber' => $card->number,
            'column' => 'tech-design',
            'card' => ['interactiveRun' => false],
        ], $this->onlyPayload());
    }

    /** The row is written before an approval moves the card, so it sees the run open. */
    public function test_the_row_says_the_stage_card_has_an_open_run(): void
    {
        $document = $this->document(['design', 'decisions']);
        $card = $this->card('tech-design', $document);
        $runs = self::getContainer()->get(InteractiveRuns::class);
        self::assertInstanceOf(InteractiveRuns::class, $runs);
        $runs->open($this->project, $card->id ?? throw new \LogicException('A created card has an id.'), $card->number, Uuid::v4(), 'pairing');

        $this->submit($document, Verdict::Approved);

        self::assertSame(['interactiveRun' => true], $this->onlyPayload()['card']);
    }

    /** The row names the column the card left, because it is written before the card moves. */
    public function test_an_approval_names_the_column_the_card_leaves(): void
    {
        $document = $this->document(['design', 'decisions']);
        $card = $this->card('tech-design', $document);

        $this->submit($document, Verdict::Approved);

        $payload = $this->onlyPayload();
        self::assertSame((string) $card->id, $payload['cardId']);
        self::assertSame($card->number, $payload['cardNumber']);
        self::assertSame('tech-design', $payload['column']);
        self::assertSame('implementation', $card->column->slug);
    }

    public function test_a_document_with_no_stage_names_no_card(): void
    {
        $document = $this->document(['plan']);
        $card = $this->card('tech-design', $document);

        $this->submit($document, Verdict::ChangesRequested);

        $payload = $this->onlyPayload();
        self::assertSame([(string) $card->id], $payload['cardIds']);
        self::assertArrayHasKey('cardId', $payload);
        self::assertNull($payload['cardId']);
        self::assertArrayHasKey('cardNumber', $payload);
        self::assertNull($payload['cardNumber']);
        self::assertArrayHasKey('column', $payload);
        self::assertNull($payload['column']);
        self::assertArrayNotHasKey('card', $payload);
    }

    /** @param list<string> $tags */
    private function document(array $tags): Document
    {
        $handler = self::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $handler);

        return $handler(new CreateDocumentCommand($this->project, 'A design', '# A design', tagNames: $tags));
    }

    private function card(string $column, Document $document): Card
    {
        $handler = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $handler);

        return $handler(new CreateCardCommand(
            project: $this->project,
            title: 'A card',
            body: 'Body',
            type: CardType::Feature,
            column: $this->column($this->project, $column),
            documentIds: [(string) $document->id],
        ));
    }

    private function submit(Document $document, Verdict $verdict): void
    {
        $handler = self::getContainer()->get(SubmitReviewHandler::class);
        self::assertInstanceOf(SubmitReviewHandler::class, $handler);

        $handler(new SubmitReviewCommand($this->reviewer, $document, $verdict->value, 1, 'A note.'));
    }

    /** @return array<mixed> */
    private function onlyPayload(): array
    {
        $outbox = self::getContainer()->get(OutboxEventRepository::class);
        self::assertInstanceOf(OutboxEventRepository::class, $outbox);

        $rows = $outbox->findBy(['project' => $this->project->id, 'type' => ReviewEventType::REVIEW_SUBMITTED]);
        self::assertCount(1, $rows);

        $payload = json_decode($rows[0]->payload, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}
