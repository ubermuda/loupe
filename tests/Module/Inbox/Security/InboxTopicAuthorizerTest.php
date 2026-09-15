<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Security;

use App\Mercure\ProjectTopicBuilder;
use App\Mercure\UserTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Security\InboxTopicAuthorizer;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

final class InboxTopicAuthorizerTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private User $owner;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->owner = $this->owner($em, 'inbox-topic-owner');
        $em->flush();
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
    }

    public function test_the_user_the_topic_names_may_subscribe(): void
    {
        $this->signIn($this->owner);

        self::assertTrue($this->authorizer()->mayCurrentUserSubscribe($this->inboxTopic($this->owner)));
    }

    public function test_another_user_is_refused(): void
    {
        $stranger = $this->owner($this->em, 'inbox-topic-stranger');
        $this->em->flush();
        $this->signIn($stranger);

        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->inboxTopic($this->owner)));
        // Guard: the stranger passes on a topic of their own, so the refusal is about the user.
        self::assertTrue($this->authorizer()->mayCurrentUserSubscribe($this->inboxTopic($stranger)));
    }

    public function test_nobody_signed_in_is_refused(): void
    {
        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->inboxTopic($this->owner)));
    }

    public function test_the_owner_is_refused_while_the_inbox_is_off(): void
    {
        $this->signIn($this->owner);
        $this->switchFlag($this->em, InboxInstallFlags::FLAG_INBOX_ENABLED, false);

        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->inboxTopic($this->owner)));
    }

    public function test_it_claims_no_other_topic(): void
    {
        $this->signIn($this->owner);
        $ownerId = $this->owner->id ?? throw new \LogicException('The owner has no id.');

        $userTopics = self::getContainer()->get(UserTopicBuilder::class);
        self::assertInstanceOf(UserTopicBuilder::class, $userTopics);
        $projectTopics = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $projectTopics);

        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($userTopics->forUser($ownerId)));
        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($projectTopics->forBoard(Uuid::v7())));
    }

    private function signIn(User $user): void
    {
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function inboxTopic(User $user): string
    {
        $topics = self::getContainer()->get(UserTopicBuilder::class);
        self::assertInstanceOf(UserTopicBuilder::class, $topics);

        return $topics->forInbox($user->id ?? throw new \LogicException('The user has no id.'));
    }

    private function authorizer(): InboxTopicAuthorizer
    {
        $authorizer = self::getContainer()->get(InboxTopicAuthorizer::class);
        self::assertInstanceOf(InboxTopicAuthorizer::class, $authorizer);

        return $authorizer;
    }
}
