<?php

declare(strict_types=1);

namespace App\Tests\Mercure;

use App\Mercure\UserTopicBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class UserTopicBuilderTest extends KernelTestCase
{
    public function test_topic_is_namespaced_by_the_apps_default_uri_and_the_user(): void
    {
        self::bootKernel();

        $builder = self::getContainer()->get(UserTopicBuilder::class);
        self::assertInstanceOf(UserTopicBuilder::class, $builder);
        $defaultUri = self::getContainer()->getParameter('router.request_context.base_url');
        self::assertIsString($defaultUri);

        $userId = Uuid::v7();

        self::assertSame(rtrim($defaultUri, '/').'/users/'.$userId.'/events', $builder->forUser($userId));
        self::assertNotSame($builder->forUser($userId), $builder->forUser(Uuid::v7()));
    }

    public function test_the_inbox_topic_is_apart_from_the_agent_topic_and_names_its_user(): void
    {
        self::bootKernel();

        $builder = self::getContainer()->get(UserTopicBuilder::class);
        self::assertInstanceOf(UserTopicBuilder::class, $builder);
        $userId = Uuid::v7();

        $topic = $builder->forInbox($userId);

        self::assertStringEndsWith('/users/'.$userId.'/inbox', $topic);
        self::assertNotSame($builder->forUser($userId), $topic);
        self::assertEquals($userId, $builder->userIdFromInboxTopic($topic));
        self::assertNull($builder->userIdFromInboxTopic($builder->forUser($userId)));
        self::assertNull($builder->userIdFromInboxTopic(str_replace((string) $userId, 'not-a-uuid', $topic)));
        self::assertNull($builder->userIdFromInboxTopic('https://elsewhere.test/users/'.$userId.'/inbox'));
    }
}
