<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Command\AddCommentCommand;
use App\Module\SiteReview\Command\AddCommentHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Service\ResetInterface;
use Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

/**
 * Drives the real AddCommentHandler rather than dispatching the event by hand,
 * because the listener's contract is about running inside that transaction.
 */
final class LinkCardOnSiteReviewCommentCreatedTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AddCommentHandler $addComment;
    private CardSiteReviewCommentRepository $links;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $handler = self::getContainer()->get(AddCommentHandler::class);
        self::assertInstanceOf(AddCommentHandler::class, $handler);
        $this->addComment = $handler;

        $links = self::getContainer()->get(CardSiteReviewCommentRepository::class);
        self::assertInstanceOf(CardSiteReviewCommentRepository::class, $links);
        $this->links = $links;

        $this->setBoardEnabled(true);
    }

    public function test_a_comment_naming_a_card_of_its_own_project_is_linked(): void
    {
        [$project, $card] = $this->projectWithCard('link-ok');

        $comment = ($this->addComment)(new AddCommentCommand(
            $project,
            'the launcher overlaps the footer',
            'https://preview/x',
            context: 'card:'.$card->id,
        ));

        $found = $this->links->findForCard($card);
        self::assertCount(1, $found);
        self::assertSame((string) $comment->id, (string) $found[0]->comment->id);
    }

    /**
     * The one that matters. `data-context` comes from a page, so a caller picks
     * the value, and a widget token is bound to one project.
     */
    public function test_a_card_belonging_to_another_project_is_refused(): void
    {
        [, $card] = $this->projectWithCard('link-foreign-card');
        [$other] = $this->projectWithCard('link-foreign-site');

        ($this->addComment)(new AddCommentCommand(
            $other,
            'filed against a card of a different project',
            'https://preview/x',
            context: 'card:'.$card->id,
        ));

        self::assertSame([], $this->links->findForCard($card));
    }

    public function test_a_context_the_board_cannot_use_is_skipped_without_failing_the_save(): void
    {
        [$project, $card] = $this->projectWithCard('link-skips');

        // Each of these must leave the comment saved and the card unlinked. A
        // listener that threw would abort the comment instead.
        $contexts = [
            null,
            '',
            'branch:feat/x',
            'card:not-a-uuid',
            'card:0199c0de-0000-7000-8000-0000000000ff',
        ];
        foreach ($contexts as $index => $context) {
            $comment = ($this->addComment)(new AddCommentCommand(
                $project,
                'note '.$index,
                'https://preview/x',
                context: $context,
            ));
            self::assertNotNull($comment->id);
        }

        self::assertSame([], $this->links->findForCard($card));
    }

    public function test_no_link_is_written_while_the_board_is_switched_off(): void
    {
        [$project, $card] = $this->projectWithCard('link-flag-off');

        // Guard: prove the fixture links at all before asserting it does not.
        ($this->addComment)(new AddCommentCommand(
            $project, 'with the board on', 'https://preview/x', context: 'card:'.$card->id,
        ));
        self::assertCount(1, $this->links->findForCard($card));

        $this->setBoardEnabled(false);
        ($this->addComment)(new AddCommentCommand(
            $project, 'with the board off', 'https://preview/x', context: 'card:'.$card->id,
        ));

        self::assertCount(1, $this->links->findForCard($card));
    }

    public function test_deleting_a_comment_takes_its_link_with_it(): void
    {
        [$project, $card] = $this->projectWithCard('link-cascade');

        $comment = ($this->addComment)(new AddCommentCommand(
            $project, 'goes away', 'https://preview/x', context: 'card:'.$card->id,
        ));
        self::assertCount(1, $this->links->findForCard($card));

        // SiteReview knows nothing about the link, so the database cascade is
        // what keeps the row from outliving its comment.
        $this->em->remove($comment);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->find(Card::class, $card->id);
        self::assertNotNull($reloaded);
        self::assertSame([], $this->links->findForCard($reloaded));
    }

    private function setBoardEnabled(bool $enabled): void
    {
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[BoardInstallFlags::FLAG_BOARD_ENABLED]->value = $enabled;
        $this->em->flush();

        // The reader caches every flag for the life of a request, and this test
        // never starts a second one. Symfony's services_resetter does this
        // between requests and between messenger messages.
        $reader = self::getContainer()->get(FeatureFlagReaderInterface::class);
        self::assertInstanceOf(ResetInterface::class, $reader);
        $reader->reset();
    }

    /** @return array{Project, Card} */
    private function projectWithCard(string $slug): array
    {
        $owner = new User(fullName: 'Riley', email: $slug.'-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, $slug);
        $this->em->persist($project);
        $card = new Card($project, 'Make the footer behave', 'body', 1);
        $this->em->persist($card);
        $this->em->flush();

        return [$project, $card];
    }
}
