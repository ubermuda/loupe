<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Command\BindWorkflowTemplateHandler;
use App\Module\Workflow\Entity\WorkflowBinding;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** Projects with one column per Lifecycle slot, for the tests that bind a template. */
trait WorkflowProjects
{
    use BoardColumnFixtures;

    private const array LIFECYCLE_COLUMNS = [
        'next' => 'next',
        'product-design' => 'product-design',
        'tech-design' => 'tech-design',
        'implementation' => 'in-progress',
        'in-review' => 'in-review',
    ];

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** A flushed project with the seeded columns and one more column for each Lifecycle slot the seed lacks. */
    private function workflowProject(string $name): Project
    {
        $em = $this->em();
        $owner = new User(fullName: 'Riley', email: $name.'-'.uniqid().'@example.com', password: 'hashed');
        $em->persist($owner);
        $project = new Project($owner, $name.'-'.uniqid());
        $em->persist($project);
        $this->seedColumns($project);
        $em->flush();

        foreach (['product-design', 'tech-design', 'in-review'] as $position => $slug) {
            $column = new BoardColumn(project: $project, label: $slug, slug: $slug, position: 10 + $position);
            $em->persist($column);
            $this->seededColumns[$project->id.'/'.$slug] = $column;
        }
        $em->flush();

        return $project;
    }

    /** @return array<string, Uuid> each Lifecycle slot key, mapped to the id of its column */
    private function lifecycleColumns(Project $project): array
    {
        $ids = [];
        foreach (self::LIFECYCLE_COLUMNS as $slot => $slug) {
            $ids[$slot] = $this->column($project, $slug)->id ?? throw new \LogicException('The column is not flushed.');
        }

        return $ids;
    }

    private function bindHandler(): BindWorkflowTemplateHandler
    {
        $handler = self::getContainer()->get(BindWorkflowTemplateHandler::class);
        self::assertInstanceOf(BindWorkflowTemplateHandler::class, $handler);

        return $handler;
    }

    private function bindLifecycle(Project $project): WorkflowBinding
    {
        return $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'lifecycle', $this->lifecycleColumns($project)));
    }
}
