<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Workflow\BoardAutomationState;
use App\Module\Workflow\Contract\BoardSettings;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BoardAutomationStateTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_the_automation_follows_the_stored_setting_of_the_project(): void
    {
        $off = $this->makeProject('automation-off');
        $on = $this->makeProject('automation-on');
        $this->em->persist(new BoardAutomationSettings($on, enabled: true));
        $this->em->persist(new BoardAutomationSettings($off, enabled: false));
        $this->em->flush();

        $settings = self::getContainer()->get(BoardSettings::class);
        self::assertInstanceOf(BoardAutomationState::class, $settings);

        self::assertTrue($settings->automationEnabled($on->id ?? throw new \LogicException()));
        self::assertFalse($settings->automationEnabled($off->id ?? throw new \LogicException()));
    }
}
