<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Security;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Security\InboxItemVoter;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class InboxItemVoterTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function attributes(): iterable
    {
        yield 'view' => [InboxItemVoter::VIEW];
        yield 'answer' => [InboxItemVoter::ANSWER];
    }

    #[DataProvider('attributes')]
    public function test_the_project_owner_is_granted(string $attribute): void
    {
        $owner = self::user('owner');

        self::assertSame(VoterInterface::ACCESS_GRANTED, new InboxItemVoter()->vote(self::token($owner), self::item($owner), [$attribute]));
    }

    #[DataProvider('attributes')]
    public function test_a_stranger_is_denied(string $attribute): void
    {
        $item = self::item(self::user('owner'));

        self::assertSame(VoterInterface::ACCESS_DENIED, new InboxItemVoter()->vote(self::token(self::user('stranger')), $item, [$attribute]));
    }

    #[DataProvider('attributes')]
    public function test_another_subject_is_abstained_on(string $attribute): void
    {
        $owner = self::user('owner');
        $project = new Project($owner, 'inbox');

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, new InboxItemVoter()->vote(self::token($owner), $project, [$attribute]));
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
