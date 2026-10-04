<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ForgePullRequestRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ForgePullRequestRepository $pullRequests;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $pullRequests = self::getContainer()->get(ForgePullRequestRepository::class);
        self::assertInstanceOf(ForgePullRequestRepository::class, $pullRequests);
        $this->pullRequests = $pullRequests;
    }

    public function test_find_by_keys_answers_the_rows_of_the_keys_in_any_case(): void
    {
        $project = $this->project();
        $projectId = $project->id ?? throw new \LogicException('The project is persisted.');
        $seven = $this->pullRequests->insertIfMissing($projectId, 'github', 'ubermuda/loupe', 7);
        $eight = $this->pullRequests->insertIfMissing($projectId, 'github', 'ubermuda/loupe', 8);
        $this->pullRequests->insertIfMissing($projectId, 'github', 'ubermuda/other', 7);
        $this->pullRequests->insertIfMissing($projectId, 'github', 'ubermuda/loupe', 9);
        $this->pullRequests->insertIfMissing($this->projectId(), 'github', 'ubermuda/loupe', 7);

        $rows = $this->pullRequests->findByKeys($projectId, [
            ['forge' => 'github', 'repository' => 'Ubermuda/Loupe', 'number' => 7],
            ['forge' => 'github', 'repository' => 'ubermuda/loupe', 'number' => 8],
            ['forge' => 'gitlab', 'repository' => 'ubermuda/loupe', 'number' => 9],
            ['forge' => 'github', 'repository' => 'ubermuda/missing', 'number' => 7],
        ]);

        $ids = array_map(static fn (ForgePullRequest $row): string => (string) $row->id, $rows);
        sort($ids);
        $expected = [(string) $seven, (string) $eight];
        sort($expected);
        self::assertSame($expected, $ids);
    }

    public function test_find_by_keys_answers_nothing_for_no_keys(): void
    {
        $projectId = $this->projectId();
        $this->pullRequests->insertIfMissing($projectId, 'github', 'ubermuda/loupe', 7);

        self::assertSame([], $this->pullRequests->findByKeys($projectId, []));
    }

    public function test_delete_by_key_deletes_the_row_of_the_key_in_any_case_and_no_other(): void
    {
        $projectId = $this->projectId();
        $otherProjectId = $this->projectId();
        $this->pullRequests->insertIfMissing($projectId, 'github', 'ubermuda/loupe', 7);
        $eight = $this->pullRequests->insertIfMissing($projectId, 'github', 'ubermuda/loupe', 8);
        $elsewhere = $this->pullRequests->insertIfMissing($otherProjectId, 'github', 'ubermuda/loupe', 7);

        $this->pullRequests->deleteByKey($projectId, 'github', 'Ubermuda/Loupe', 7);

        $remaining = $this->em->getConnection()->fetchFirstColumn(
            'SELECT id FROM forge_pull_requests WHERE project_id IN (:projects) ORDER BY number, project_id',
            ['projects' => [$projectId->toRfc4122(), $otherProjectId->toRfc4122()]],
            ['projects' => ArrayParameterType::STRING],
        );
        $expected = [(string) $elsewhere, (string) $eight];
        sort($expected);
        sort($remaining);
        self::assertSame($expected, $remaining);
    }

    public function test_find_by_head_branch_answers_the_rows_of_one_repository_whose_head_is_the_branch(): void
    {
        $project = $this->project();
        $projectId = $project->id ?? throw new \LogicException('The project is persisted.');
        $match = $this->row($project, 'ubermuda/loupe', 1, 'epic/42');
        $merged = $this->row($project, 'ubermuda/loupe', 2, 'epic/42');
        $merged->state = PullRequestState::Merged;
        $this->row($project, 'ubermuda/loupe', 3, 'epic/43');
        $this->row($project, 'ubermuda/other', 4, 'epic/42');
        $this->row($this->project(), 'ubermuda/loupe', 5, 'epic/42');
        $this->em->flush();

        $rows = $this->pullRequests->findByHeadBranch($projectId, 'github', 'Ubermuda/Loupe', 'epic/42');

        self::assertSame([$match->number, $merged->number], array_map(static fn (ForgePullRequest $row): int => $row->number, $rows));
        self::assertSame([], $this->pullRequests->findByHeadBranch($projectId, 'gitlab', 'ubermuda/loupe', 'epic/42'));
    }

    private function row(Project $project, string $repository, int $number, string $headBranch): ForgePullRequest
    {
        $row = new ForgePullRequest($project, 'github', $repository, $number);
        $row->headBranch = $headBranch;
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }

    private function projectId(): Uuid
    {
        return $this->project()->id ?? throw new \LogicException('The project is persisted.');
    }

    private function project(): Project
    {
        $owner = new User(fullName: 'Riley', email: 'forge-rows-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($owner, 'forge-rows-'.uniqid());
        $this->em->persist($owner);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
