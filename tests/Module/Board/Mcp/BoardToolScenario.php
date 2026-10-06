<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\BoardColumnFixtures;

/**
 * KernelTestCase helper for the board MCP tools.
 *
 * Requires an `$em` EntityManagerInterface property on the using class, the
 * same contract McpTokenScenario has.
 */
trait BoardToolScenario
{
    use BoardColumnFixtures;

    private function makeProject(string $label): Project
    {
        $owner = new User(fullName: 'Riley', email: $label.'-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);

        $project = new Project($owner, 'board-'.uniqid());
        $this->em->persist($project);
        $this->seedColumns($project);
        $this->em->flush();

        return $project;
    }
}
