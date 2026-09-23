<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Forge\Service\ForgeClaim;
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

    public function test_a_first_claim_creates_the_row_of_the_project(): void
    {
        $project = $this->project('first');

        $claim = $this->hook($project, '1001', 'acme/widgets');

        self::assertSame(ForgeClaimOutcome::Owned, $claim->outcome);
        self::assertNull($claim->movedFrom);
        $row = $this->rowOf($project, '1001');
        self::assertNotNull($row);
        self::assertSame('acme/widgets', $row->path);
        self::assertSame(ForgeRepositorySource::Hook, $row->source);
        self::assertNull($row->sourceRef);
        self::assertNull($row->lastAcceptedAt);
    }

    public function test_a_second_claim_by_the_same_project_is_already_owned(): void
    {
        $project = $this->project('again');
        $first = $this->hook($project, '1002', 'acme/widgets');

        $claim = $this->hook($project, '1002', 'ACME/Widgets');

        self::assertSame(ForgeClaimOutcome::AlreadyOwned, $claim->outcome);
        self::assertSame($first->repository, $claim->repository);
        self::assertNull($claim->movedFrom, 'A change of case alone is not a move.');
        self::assertSame(1, $this->rowCount('1002'));
    }

    /** A hook signature proves only that the project owner or GitHub signed, so it blocks nobody. */
    public function test_a_hook_claim_never_refuses_and_never_blocks(): void
    {
        $installed = $this->project('installed');
        $hooked = $this->project('hooked');
        $this->installation($installed, '1003', 'acme/widgets', '1');

        $hookClaim = $this->hook($hooked, '1003', 'acme/widgets');
        $installationClaim = $this->installation($this->project('later'), '1014', 'acme/other', '2');
        $afterHook = $this->installation($this->project('after-hook'), '1003', 'acme/widgets', '3');

        self::assertSame(ForgeClaimOutcome::Owned, $hookClaim->outcome);
        self::assertSame(ForgeClaimOutcome::Owned, $installationClaim->outcome);
        self::assertSame(ForgeClaimOutcome::Refused, $afterHook->outcome, 'The first installation row still holds.');
        self::assertSame(2, $this->rowCount('1003'));
        self::assertEquals($installed->id, $this->repositories->installationOwnerOf('github', '1003')?->project->id);
    }

    public function test_a_hook_row_does_not_block_an_installation(): void
    {
        $this->hook($this->project('hook-first'), '1015', 'acme/widgets');
        $installed = $this->project('installed');

        $claim = $this->installation($installed, '1015', 'acme/widgets', '4');

        self::assertSame(ForgeClaimOutcome::Owned, $claim->outcome);
        self::assertEquals($installed->id, $this->repositories->installationOwnerOf('github', '1015')?->project->id);
    }

    public function test_an_installation_claim_refuses_when_another_project_holds_an_installation_row(): void
    {
        $owner = $this->project('owner');
        $this->installation($owner, '1016', 'acme/widgets', '5');
        $stranger = $this->project('stranger');

        $claim = $this->installation($stranger, '1016', 'acme/gadgets', '6');

        self::assertSame(ForgeClaimOutcome::Refused, $claim->outcome);
        self::assertNull($claim->repository);
        self::assertNull($claim->movedFrom);
        self::assertNull($this->rowOf($stranger, '1016'));
        self::assertSame('acme/widgets', $this->rowOf($owner, '1016')?->path);
    }

    public function test_an_installation_claim_upgrades_the_hook_row_of_the_project(): void
    {
        $project = $this->project('upgrade');
        $this->hook($project, '1017', 'acme/widgets');

        $claim = $this->installation($project, '1017', 'acme/widgets', '7');

        self::assertSame(ForgeClaimOutcome::AlreadyOwned, $claim->outcome);
        self::assertSame(1, $this->rowCount('1017'));
        $row = $this->rowOf($project, '1017');
        self::assertNotNull($row);
        self::assertSame(ForgeRepositorySource::Installation, $row->source);
        self::assertSame('7', $row->sourceRef);
    }

    public function test_a_hook_claim_keeps_an_installation_row(): void
    {
        $project = $this->project('keep');
        $this->installation($project, '1018', 'acme/widgets', '8');

        $this->hook($project, '1018', 'acme/widgets');

        self::assertSame(ForgeRepositorySource::Installation, $this->rowOf($project, '1018')?->source);
    }

    public function test_a_refused_claim_logs_the_claiming_project_and_never_the_owner(): void
    {
        $logger = new RecordingLogger();
        $repositories = $this->withLogger($logger);
        $owner = $this->project('log-owner');
        $repositories->claim($owner, 'github', '1012', 'acme/widgets', ForgeRepositorySource::Installation, '9');
        $stranger = $this->project('log-stranger');

        $repositories->claim($stranger, 'github', '1012', 'acme/widgets', ForgeRepositorySource::Installation, '10');

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
        $repositories->claim($project, 'github', '1013', 'acme/widgets', ForgeRepositorySource::Hook);

        $repositories->claim($project, 'github', '1013', 'acme/gadgets', ForgeRepositorySource::Hook);

        self::assertSame([[
            'level' => 'info',
            'message' => 'forge.repository_moved',
            'context' => ['forge' => 'github', 'externalId' => '1013', 'projectId' => (string) $project->id, 'from' => 'acme/widgets', 'to' => 'acme/gadgets'],
        ]], $logger->records);
    }

    public function test_the_same_external_id_on_another_forge_is_a_different_repository(): void
    {
        $this->installation($this->project('github'), '1004', 'acme/widgets', '11');

        $claim = $this->repositories->claim($this->project('gitlab'), 'gitlab', '1004', 'acme/widgets', ForgeRepositorySource::Installation, '12');

        self::assertSame(ForgeClaimOutcome::Owned, $claim->outcome);
    }

    public function test_a_claim_under_a_new_path_moves_only_the_row_of_the_project(): void
    {
        $project = $this->project('moved');
        $other = $this->project('unmoved');
        $this->hook($project, '1005', 'acme/widgets');
        $this->hook($other, '1005', 'acme/widgets');

        $claim = $this->hook($project, '1005', 'acme/gadgets');

        self::assertSame(ForgeClaimOutcome::AlreadyOwned, $claim->outcome);
        self::assertSame('acme/widgets', $claim->movedFrom);
        self::assertSame('acme/gadgets', $this->rowOf($project, '1005')?->path);
        self::assertSame('acme/widgets', $this->rowOf($other, '1005')?->path);
    }

    public function test_release_removes_the_row_of_the_project_only(): void
    {
        $project = $this->project('release');
        $keeper = $this->project('keeper');
        $this->hook($project, '1006', 'acme/widgets');
        $this->hook($keeper, '1006', 'acme/widgets');

        $this->repositories->release($project, 'github', '1006');

        self::assertNull($this->rowOf($project, '1006'));
        self::assertNotNull($this->rowOf($keeper, '1006'));
    }

    public function test_release_installation_removes_the_rows_of_that_installation_only(): void
    {
        $project = $this->project('uninstalled');
        $this->installation($project, '1007', 'acme/one', '13');
        $this->installation($project, '1019', 'acme/two', '13');
        $this->installation($project, '1020', 'acme/three', '14');
        $this->hook($project, '1021', 'acme/four');

        $this->repositories->releaseInstallation('github', '13', '1019');
        self::assertNotNull($this->rowOf($project, '1007'));
        self::assertNull($this->rowOf($project, '1019'));

        $this->repositories->releaseInstallation('github', '13');
        self::assertNull($this->rowOf($project, '1007'));
        self::assertNotNull($this->rowOf($project, '1020'));
        self::assertNotNull($this->rowOf($project, '1021'));
    }

    public function test_accepted_stamps_a_repository_that_has_no_stamp(): void
    {
        [$project, $repository] = $this->claimed('1008');
        $now = new \DateTimeImmutable('2026-09-23 12:00:00');

        $this->repositories->accepted($repository, $now);

        self::assertEquals($now, $this->rowOf($project, '1008')?->lastAcceptedAt);
    }

    public function test_accepted_skips_a_stamp_under_a_minute_old(): void
    {
        [$project, $repository] = $this->claimed('1009');
        $first = new \DateTimeImmutable('2026-09-23 12:00:00');
        $this->repositories->accepted($repository, $first);

        $this->repositories->accepted($repository, $first->modify('+59 seconds'));

        self::assertEquals($first, $this->rowOf($project, '1009')?->lastAcceptedAt);
    }

    public function test_accepted_restamps_after_a_minute(): void
    {
        [$project, $repository] = $this->claimed('1010');
        $first = new \DateTimeImmutable('2026-09-23 12:00:00');
        $this->repositories->accepted($repository, $first);

        $this->repositories->accepted($repository, $first->modify('+60 seconds'));

        self::assertEquals($first->modify('+60 seconds'), $this->rowOf($project, '1010')?->lastAcceptedAt);
    }

    public function test_no_installation_owns_a_repository_that_only_hooks_hold(): void
    {
        $this->hook($this->project('hook-only'), '1022', 'acme/widgets');

        self::assertNull($this->repositories->installationOwnerOf('github', '1022'));
        self::assertNull($this->repositories->installationOwnerOf('github', 'no-such-repository'));
    }

    private function hook(Project $project, string $externalId, string $path): ForgeClaim
    {
        return $this->repositories->claim($project, 'github', $externalId, $path, ForgeRepositorySource::Hook);
    }

    private function installation(Project $project, string $externalId, string $path, string $installationId): ForgeClaim
    {
        return $this->repositories->claim($project, 'github', $externalId, $path, ForgeRepositorySource::Installation, $installationId);
    }

    private function rowOf(Project $project, string $externalId): ?ForgeRepository
    {
        $this->em->clear();
        $project = $this->em->find(Project::class, $project->id) ?? throw new \LogicException('The project is gone.');

        return $this->forgeRepositoryRepository()->findOneForProject($project, 'github', $externalId);
    }

    private function withLogger(RecordingLogger $logger): ForgeRepositories
    {
        return new ForgeRepositories($this->forgeRepositoryRepository(), $this->em, $logger);
    }

    private function forgeRepositoryRepository(): ForgeRepositoryRepository
    {
        $forgeRepositories = self::getContainer()->get(ForgeRepositoryRepository::class);
        self::assertInstanceOf(ForgeRepositoryRepository::class, $forgeRepositories);

        return $forgeRepositories;
    }

    /** @return array{Project, ForgeRepository} */
    private function claimed(string $externalId): array
    {
        $project = $this->project('stamp');

        return [$project, $this->hook($project, $externalId, 'acme/widgets')->repository ?? throw new \LogicException('A first claim always owns.')];
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
