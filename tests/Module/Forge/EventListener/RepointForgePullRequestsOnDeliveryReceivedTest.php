<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Event\ForgeDeliveryReceived;
use App\Module\Forge\ForgeDelivery;
use App\Module\Forge\ForgeEventType;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class RepointForgePullRequestsOnDeliveryReceivedTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_a_move_repoints_the_rows_of_that_project_and_forge_only(): void
    {
        $project = $this->project();
        $other = $this->project();
        $moved = $this->row($project, 'github', 'acme/widgets', 1);
        $otherForge = $this->row($project, 'gitlab', 'acme/widgets', 1);
        $otherProject = $this->row($other, 'github', 'acme/widgets', 1);

        $this->dispatch($project, new ForgeDelivery(ForgeEventType::REPOSITORY_MOVED, 'github', 'ACME/Widgets', movedTo: 'Acme/Gadgets'));

        self::assertSame('acme/gadgets', $this->repositoryOf($moved));
        self::assertSame('acme/widgets', $this->repositoryOf($otherForge));
        self::assertSame('acme/widgets', $this->repositoryOf($otherProject));
    }

    public function test_a_row_that_already_exists_under_the_new_path_wins(): void
    {
        $project = $this->project();
        $old = $this->row($project, 'github', 'acme/widgets', 1);
        $new = $this->row($project, 'github', 'acme/gadgets', 1);
        $alone = $this->row($project, 'github', 'acme/widgets', 2);

        $this->dispatch($project, new ForgeDelivery(ForgeEventType::REPOSITORY_MOVED, 'github', 'acme/widgets', movedTo: 'acme/gadgets'));

        self::assertNull($this->repositoryOf($old));
        self::assertSame('acme/gadgets', $this->repositoryOf($new));
        self::assertSame('acme/gadgets', $this->repositoryOf($alone));
    }

    public function test_a_change_of_case_alone_keeps_every_row(): void
    {
        $project = $this->project();
        $row = $this->row($project, 'github', 'acme/widgets', 1);

        $this->dispatch($project, new ForgeDelivery(ForgeEventType::REPOSITORY_MOVED, 'github', 'acme/widgets', movedTo: 'Acme/Widgets'));

        self::assertSame('acme/widgets', $this->repositoryOf($row));
    }

    public function test_another_delivery_type_changes_nothing(): void
    {
        $project = $this->project();
        $row = $this->row($project, 'github', 'acme/widgets', 1);

        $this->dispatch($project, new ForgeDelivery(ForgeEventType::MERGED, 'github', 'acme/widgets', 1, movedTo: 'acme/gadgets'));

        self::assertSame('acme/widgets', $this->repositoryOf($row));
    }

    private function dispatch(Project $project, ForgeDelivery $delivery): void
    {
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch(new ForgeDeliveryReceived($project->id ?? throw new \LogicException('persisted'), [$delivery]));
        $this->em->clear();
    }

    private function repositoryOf(ForgePullRequest $row): ?string
    {
        $pullRequests = self::getContainer()->get(ForgePullRequestRepository::class);
        self::assertInstanceOf(ForgePullRequestRepository::class, $pullRequests);

        return $pullRequests->find($row->id)?->repository;
    }

    private function project(): Project
    {
        $owner = new User(fullName: 'Riley', email: 'repoint-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($owner, 'repoint-'.uniqid());
        $this->em->persist($owner);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    private function row(Project $project, string $forge, string $repository, int $number): ForgePullRequest
    {
        $row = new ForgePullRequest($project, $forge, $repository, $number);
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }
}
