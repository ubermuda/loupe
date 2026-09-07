<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Project\Entity\Project;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

/**
 * KernelTestCase helper for the board MCP tools.
 *
 * Requires an `$em` EntityManagerInterface property on the using class, the
 * same contract McpTokenScenario has.
 */
trait BoardToolScenario
{
    /** The board ships off, so every test that calls a tool has to switch it on. */
    private function enableBoard(): void
    {
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[BoardInstallFlags::FLAG_BOARD_ENABLED]->value = true;
        $this->em->flush();
    }

    private function makeProject(string $label): Project
    {
        $owner = new User(fullName: 'Riley', email: $label.'-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);

        $project = new Project($owner, 'board-'.uniqid());
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
