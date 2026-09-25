<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;

/** A board with a second terminal column, and feedback linked to its cards. */
trait ResolveFeedbackScenario
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;

    private function feedbackProject(string $label): Project
    {
        $owner = new User(fullName: 'Riley', email: $label.'-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, $label.'-'.uniqid());
        $this->em->persist($project);
        $this->seedColumns($project);
        $this->em->persist(new BoardColumn($project, 'Shipped', 'shipped', 4, terminal: true));
        $this->em->flush();

        return $project;
    }

    private function cardIn(Project $project, string $slug, int $number = 1): Card
    {
        $card = new Card(project: $project, column: $this->column($project, $slug), title: 'Fix it', body: 'Body', number: $number);
        $this->em->persist($card);

        return $card;
    }

    private function feedback(Card $card, SiteReviewCommentStatus $status): SiteReviewComment
    {
        $comment = new SiteReviewComment($card->project, 0, 'Move the button', 'https://example.com');
        $comment->status = $status;
        $this->em->persist($comment);
        $this->em->persist(new CardSiteReviewComment($card, $comment));

        return $comment;
    }

    private function statusOf(SiteReviewComment $comment): SiteReviewCommentStatus
    {
        $this->em->clear();
        $fresh = $this->em->find(SiteReviewComment::class, $comment->id);
        self::assertInstanceOf(SiteReviewComment::class, $fresh);

        return $fresh->status;
    }
}
