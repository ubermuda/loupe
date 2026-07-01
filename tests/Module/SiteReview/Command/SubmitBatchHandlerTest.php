<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Command;

use App\Module\Account\Entity\User;
use App\Module\SiteReview\Command\SubmitBatchCommand;
use App\Module\SiteReview\Command\SubmitBatchHandler;
use App\Module\SiteReview\Service\SiteReviewTopicBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class SubmitBatchHandlerTest extends KernelTestCase
{
    public function test_publishes_private_update_to_owner_topic(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $topicBuilder = $container->get(SiteReviewTopicBuilder::class);

        $user = new User(username: 'h@example.com', fullName: 'H', email: 'h@example.com', password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush(); // assigns $user->id, which the topic is keyed on

        $captured = null;
        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->once())->method('publish')
            ->willReturnCallback(function (Update $update) use (&$captured): string {
                $captured = $update;

                return 'urn:uuid:published';
            });

        $handler = new SubmitBatchHandler($em, $hub, $topicBuilder, new NullLogger());
        $handler(new SubmitBatchCommand($user, [
            ['body' => 'too big', 'selector' => '.card', 'text' => 'Save', 'url' => 'https://app.localhost/x'],
        ]));

        self::assertInstanceOf(Update::class, $captured);
        self::assertTrue($captured->isPrivate(), 'update must be private so only the owner receives it');
        self::assertSame(
            ['https://betterplans.dev.localhost/users/'.$user->id.'/site-reviews'],
            $captured->getTopics(),
        );
    }
}
