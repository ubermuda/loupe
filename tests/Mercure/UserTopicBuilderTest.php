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
}
