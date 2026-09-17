<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Inbox\Command\AskInboxCommand;
use App\Module\Inbox\Command\AskInboxHandler;
use App\Module\Inbox\Command\AskInboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxReviewTargetKind;
use App\Module\Inbox\Repository\InboxReviewRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class AskInboxReviewTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_a_review_has_one_explicit_target_and_keeps_its_context(bool $pullRequest): void
    {
        $owner = $this->owner($this->em, 'ask-review');
        $project = $this->project($this->em, $owner, 'ask-review');
        $document = $this->document($this->em, $project);
        $context = $this->document($this->em, $project);
        $card = $this->card($this->em, $project);
        $link = new CardPullRequest($card, 'https://github.com/example/project/pull/42');
        $this->em->persist($link);
        $this->em->flush();

        $itemId = $this->ask($project, new AskInboxItem(
            InboxItemKind::Review,
            'Review this change',
            blocking: true,
            documentIds: [(string) $context->id],
            reviewDocumentId: $pullRequest ? null : (string) $document->id,
            reviewPullRequestId: $pullRequest ? (string) $link->id : null,
        ));
        $this->em->clear();
        $reviews = self::getContainer()->get(InboxReviewRepository::class);
        self::assertInstanceOf(InboxReviewRepository::class, $reviews);
        $review = $reviews->findOneBy(['item' => $itemId]);
        self::assertNotNull($review);
        self::assertSame($pullRequest ? InboxReviewTargetKind::PullRequest : InboxReviewTargetKind::Document, $review->targetKind);
        self::assertEquals($pullRequest ? $link->id : $document->id, ($review->pullRequest ?? $review->document)?->id);
        self::assertTrue($review->item->blocking);
        self::assertNull($review->verdict);
        self::assertCount($pullRequest ? 1 : 2, $review->item->documents);
        self::assertCount($pullRequest ? 1 : 0, $review->item->cards);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_a_foreign_target_creates_no_request(bool $pullRequest): void
    {
        $owner = $this->owner($this->em, 'ask-review-foreign');
        $project = $this->project($this->em, $owner, 'ask-review-here');
        $foreign = $this->project($this->em, $owner, 'ask-review-elsewhere');
        $document = $this->document($this->em, $foreign);
        $link = new CardPullRequest($this->card($this->em, $foreign), 'https://github.com/example/project/pull/42');
        $this->em->persist($link);
        $this->em->flush();

        try {
            $this->ask($project, new AskInboxItem(
                InboxItemKind::Review,
                'Review outside this project',
                reviewDocumentId: $pullRequest ? null : (string) $document->id,
                reviewPullRequestId: $pullRequest ? (string) $link->id : null,
            ));
            self::fail('A review target must belong to the bound project.');
        } catch (DomainErrors $error) {
            self::assertArrayHasKey($pullRequest ? 'items[0].reviewPullRequestId' : 'items[0].reviewDocumentId', $error->errors);
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_items WHERE project_id = :project', ['project' => (string) $project->id]));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_asks WHERE project_id = :project', ['project' => (string) $project->id]));
        self::assertTrue($this->em->isOpen());
    }

    #[TestWith([null, null])]
    #[TestWith(['not-a-uuid', null])]
    #[TestWith([null, 'not-a-uuid'])]
    #[TestWith(['one', 'two'])]
    public function test_a_missing_or_invalid_target_is_refused(?string $documentId, ?string $pullRequestId): void
    {
        $owner = $this->owner($this->em, 'ask-review-invalid');
        $project = $this->project($this->em, $owner, 'ask-review-invalid');
        $this->em->flush();

        $this->expectException(DomainErrors::class);
        $this->ask($project, new AskInboxItem(InboxItemKind::Review, 'Review', reviewDocumentId: $documentId, reviewPullRequestId: $pullRequestId));
    }

    private function ask(Project $project, AskInboxItem $input): string
    {
        $handler = self::getContainer()->get(AskInboxHandler::class);
        self::assertInstanceOf(AskInboxHandler::class, $handler);
        $view = $handler(new AskInboxCommand($project, Uuid::v4(), [$input]));

        return (string) $view->items[0]->id;
    }
}
