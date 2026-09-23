<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Forge\Service\ForgeClaimOutcome;
use App\Module\Forge\Service\ForgeRepositories;
use App\Module\Project\Entity\Project;
use App\Tests\Support\RecordingLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The claim race itself cannot run here: the test bundle wraps each test in one
 * connection's transaction. These pin the sequential outcomes the lock guards.
 */
final class ForgeRepositoriesTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ForgeRepositories $repositories;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $repositories = self::getContainer()->get(ForgeRepositories::class);
        self::assertInstanceOf(ForgeRepositories::class, $repositories);
        $this->repositories = $repositories;
    }

    public function test_a_first_claim_owns_the_repository(): void
    {
        $project = $this->project('first');

        $claim = $this->repositories->claim($project, 'github', '1001', 'acme/widgets');

        self::assertSame(ForgeClaimOutcome::Owned, $claim->outcome);
        self::assertNull($claim->movedFrom);
        self::assertNotNull($claim->repository);
        $this->em->clear();
        $owner = $this->repositories->ownerOf('github', '1001');
        self::assertNotNull($owner);
        self::assertEquals($project->id, $owner->project->id);
        self::assertSame('acme/widgets', $owner->path);
        self::assertNull($owner->lastAcceptedAt);
    }

    public function test_a_second_claim_by_the_same_project_is_already_owned(): void
    {
        $project = $this->project('again');
        $first = $this->repositories->claim($project, 'github', '1002', 'acme/widgets');

        $claim = $this->repositories->claim($project, 'github', '1002', 'ACME/Widgets');

        self::assertSame(ForgeClaimOutcome::AlreadyOwned, $claim->outcome);
        self::assertSame($first->repository, $claim->repository);
        self::assertNull($claim->movedFrom, 'A change of case alone is not a move.');
        self::assertSame(1, $this->rowCount('1002'));
    }

    public function test_a_claim_by_another_project_is_refused_and_names_no_owner(): void
    {
        $owner = $this->project('owner');
        $this->repositories->claim($owner, 'github', '1003', 'acme/widgets');

        $claim = $this->repositories->claim($this->project('stranger'), 'github', '1003', 'acme/widgets');

        self::assertSame(ForgeClaimOutcome::Refused, $claim->outcome);
        self::assertNull($claim->repository);
        self::assertNull($claim->movedFrom);
        $row = $this->repositories->ownerOf('github', '1003');
        self::assertNotNull($row);
        self::assertSame($owner, $row->project);
    }

    public function test_a_refused_claim_under_another_path_leaves_the_owner_path(): void
    {
        $owner = $this->project('path-owner');
        $this->repositories->claim($owner, 'github', '1011', 'acme/widgets');

        $claim = $this->repositories->claim($this->project('path-stranger'), 'github', '1011', 'acme/gadgets');

        self::assertSame(ForgeClaimOutcome::Refused, $claim->outcome);
        $this->em->clear();
        self::assertSame('acme/widgets', $this->repositories->ownerOf('github', '1011')?->path);
    }

    public function test_a_refused_claim_logs_the_claiming_project_and_never_the_owner(): void
    {
        $logger = new RecordingLogger();
        $repositories = $this->withLogger($logger);
        $owner = $this->project('log-owner');
        $repositories->claim($owner, 'github', '1012', 'acme/widgets');
        $stranger = $this->project('log-stranger');

        $repositories->claim($stranger, 'github', '1012', 'acme/widgets');

        self::assertSame([[
            'level' => 'info',
            'message' => 'forge.repository_claim_refused',
            'context' => ['forge' => 'github', 'externalId' => '1012', 'projectId' => (string) $stranger->id],
        ]], $logger->records);
        self::assertStringNotContainsString((string) $owner->id, (string) json_encode($logger->records));
    }

    public function test_a_move_is_logged(): void
    {
        $logger = new RecordingLogger();
        $repositories = $this->withLogger($logger);
        $project = $this->project('log-move');
        $repositories->claim($project, 'github', '1013', 'acme/widgets');

        $repositories->claim($project, 'github', '1013', 'acme/gadgets');

        self::assertSame([[
            'level' => 'info',
            'message' => 'forge.repository_moved',
            'context' => ['forge' => 'github', 'externalId' => '1013', 'projectId' => (string) $project->id, 'from' => 'acme/widgets', 'to' => 'acme/gadgets'],
        ]], $logger->records);
    }

    public function test_the_same_external_id_on_another_forge_is_a_different_repository(): void
    {
        $this->repositories->claim($this->project('github'), 'github', '1004', 'acme/widgets');

        $claim = $this->repositories->claim($this->project('gitlab'), 'gitlab', '1004', 'acme/widgets');

        self::assertSame(ForgeClaimOutcome::Owned, $claim->outcome);
    }

    public function test_a_claim_under_a_new_path_moves_the_repository(): void
    {
        $project = $this->project('moved');
        $this->repositories->claim($project, 'github', '1005', 'acme/widgets');

        $claim = $this->repositories->claim($project, 'github', '1005', 'acme/gadgets');

        self::assertSame(ForgeClaimOutcome::AlreadyOwned, $claim->outcome);
        self::assertSame('acme/widgets', $claim->movedFrom);
        $this->em->clear();
        self::assertSame('acme/gadgets', $this->repositories->ownerOf('github', '1005')?->path);
    }

    public function test_release_removes_the_row_of_its_owner(): void
    {
        $project = $this->project('release');
        $this->repositories->claim($project, 'github', '1006', 'acme/widgets');
        self::assertSame(1, $this->rowCount('1006'));

        $this->repositories->release($project, 'github', '1006');

        self::assertSame(0, $this->rowCount('1006'));
    }

    public function test_release_by_another_project_leaves_the_row(): void
    {
        $this->repositories->claim($this->project('keeper'), 'github', '1007', 'acme/widgets');

        $this->repositories->release($this->project('intruder'), 'github', '1007');

        self::assertSame(1, $this->rowCount('1007'));
    }

    public function test_accepted_stamps_a_repository_that_has_no_stamp(): void
    {
        $repository = $this->claimed('1008');
        $now = new \DateTimeImmutable('2026-09-23 12:00:00');

        $this->repositories->accepted($repository, $now);

        $this->em->clear();
        self::assertEquals($now, $this->repositories->ownerOf('github', '1008')?->lastAcceptedAt);
    }

    public function test_accepted_skips_a_stamp_under_a_minute_old(): void
    {
        $repository = $this->claimed('1009');
        $first = new \DateTimeImmutable('2026-09-23 12:00:00');
        $this->repositories->accepted($repository, $first);

        $this->repositories->accepted($repository, $first->modify('+59 seconds'));

        $this->em->clear();
        self::assertEquals($first, $this->repositories->ownerOf('github', '1009')?->lastAcceptedAt);
    }

    public function test_accepted_restamps_after_a_minute(): void
    {
        $repository = $this->claimed('1010');
        $first = new \DateTimeImmutable('2026-09-23 12:00:00');
        $this->repositories->accepted($repository, $first);

        $this->repositories->accepted($repository, $first->modify('+60 seconds'));

        $this->em->clear();
        self::assertEquals($first->modify('+60 seconds'), $this->repositories->ownerOf('github', '1010')?->lastAcceptedAt);
    }

    public function test_owner_of_an_unknown_repository_is_null(): void
    {
        self::assertNull($this->repositories->ownerOf('github', 'no-such-repository'));
    }

    private function withLogger(RecordingLogger $logger): ForgeRepositories
    {
        $forgeRepositories = self::getContainer()->get(ForgeRepositoryRepository::class);
        self::assertInstanceOf(ForgeRepositoryRepository::class, $forgeRepositories);

        return new ForgeRepositories($forgeRepositories, $this->em, $logger);
    }

    private function claimed(string $externalId): ForgeRepository
    {
        return $this->repositories->claim($this->project('stamp'), 'github', $externalId, 'acme/widgets')->repository
            ?? throw new \LogicException('A first claim always owns.');
    }

    private function rowCount(string $externalId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM forge_repositories WHERE forge = :forge AND external_id = :id',
            ['forge' => 'github', 'id' => $externalId],
        );
    }

    private function project(string $label): Project
    {
        $owner = new User(fullName: 'Riley', email: 'forge-'.$label.'-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($owner, $label.'-'.uniqid());
        $this->em->persist($owner);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
