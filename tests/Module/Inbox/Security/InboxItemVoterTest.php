<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Security;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Repository\InboxReviewRepository;
use App\Module\Inbox\Security\InboxItemVoter;
use App\Module\Inbox\Service\InboxReviewLookup;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\DocumentVoter;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class InboxItemVoterTest extends TestCase
{
    #[TestWith([true])]
    #[TestWith([false])]
    public function test_document_review_requires_the_document_permission(bool $granted): void
    {
        $owner = self::user('reviewer');
        $project = new Project($owner, 'review');
        $document = new Document($owner, $project, 'Design');
        $item = new InboxItem($project, 1, InboxItemKind::Review, 'Review', true);
        $reviews = $this->createStub(InboxReviewRepository::class);
        $reviews->method('findOneBy')->willReturn(new InboxReview($item, $document));
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->expects($this->once())->method('isGranted')->with(DocumentVoter::CONTRIBUTE, $document)->willReturn($granted);
        $voter = new InboxItemVoter(new InboxReviewLookup($reviews), $authorization);
        self::assertSame($granted ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED,
            $voter->vote(self::token($owner), $item, [InboxItemVoter::REVIEW_DOCUMENT]));
    }

    private function voter(): InboxItemVoter
    {
        return new InboxItemVoter(new InboxReviewLookup($this->createStub(InboxReviewRepository::class)), $this->createStub(AuthorizationCheckerInterface::class));
    }

    public function test_the_project_owner_may_answer(): void
    {
        $owner = self::user('owner');

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter()->vote(self::token($owner), self::item($owner), [InboxItemVoter::ANSWER]));
    }

    public function test_a_stranger_may_not_answer(): void
    {
        $item = self::item(self::user('owner'));

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote(self::token(self::user('stranger')), $item, [InboxItemVoter::ANSWER]));
    }

    public function test_another_subject_or_attribute_is_abstained_on(): void
    {
        $owner = self::user('owner');

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter()->vote(self::token($owner), new Project($owner, 'inbox'), [InboxItemVoter::ANSWER]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter()->vote(self::token($owner), self::item($owner), ['inbox_item.view']));
    }

    private static function user(string $name): User
    {
        return new User(fullName: $name, email: $name.'@example.com', password: 'hashed');
    }

    private static function item(User $owner): InboxItem
    {
        return new InboxItem(project: new Project($owner, 'inbox'), number: 1, kind: InboxItemKind::Todo, title: 'Review', blocking: false);
    }

    private static function token(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }
}
