<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Command;

use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\MintProjectWidgetTokenCommand;
use App\Module\Project\Command\MintProjectWidgetTokenHandler;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MintProjectWidgetTokenHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MintProjectWidgetTokenHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->handler = new MintProjectWidgetTokenHandler($this->em, new NullLogger());
    }

    public function test_label_is_truncated_to_fit_the_column_for_long_project_names(): void
    {
        $project = $this->project('mint-widget-a@example.com', str_repeat('n', 100));

        $raw = ($this->handler)(new MintProjectWidgetTokenCommand($project));

        self::assertNotNull($project->widgetToken);
        self::assertSame(ApiTokenScope::SiteReview, $project->widgetToken->scope);
        self::assertTrue($project->widgetToken->matches($raw));
        self::assertSame('Widget: '.str_repeat('n', 92), $project->widgetToken->label);
        self::assertLessThanOrEqual(100, mb_strlen($project->widgetToken->label));
    }

    /** @param non-empty-string $email */
    private function project(string $email, string $name): Project
    {
        $owner = new User(username: $email, fullName: 'U', email: $email, password: 'x');
        $project = new Project($owner, $name);
        $this->em->persist($owner);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
