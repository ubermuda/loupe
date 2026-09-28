<?php

declare(strict_types=1);

namespace App\Tests\Outbox\Twig;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class ActivityTopicExtensionTest extends KernelTestCase
{
    public function test_a_template_reads_the_activity_topic_of_a_project(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $twig = self::getContainer()->get(Environment::class);
        $topics = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(Environment::class, $twig);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);
        $owner = new User(fullName: 'U', email: 'activity-topic-twig@example.com', password: 'x');
        $project = new Project($owner, 'Activity topic twig');
        $em->persist($owner);
        $em->persist($project);
        $em->flush();

        $rendered = $twig->createTemplate('{{ activity_topic(project) }}')->render(['project' => $project]);

        self::assertSame($topics->forActivity($project->id ?? throw new \LogicException('The project has no id.')), $rendered);
    }
}
