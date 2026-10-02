<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\ForgePullRequestWrites;
use App\Module\Forge\Service\PullRequestBaseChangers;
use App\Module\Forge\Service\PullRequestMergers;
use App\Module\Forge\Service\PullRequestWriteFailed;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Forge\FakePullRequestBaseChanger;
use App\Tests\Module\Forge\FakePullRequestMerger;
use App\Tests\Module\Forge\FakePullRequestStateReader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class ForgePullRequestWritesTest extends KernelTestCase
{
    private const string NOW = '2026-10-02 12:00:00';

    private EntityManagerInterface $em;
    private FakePullRequestMerger $merger;
    private FakePullRequestBaseChanger $changer;
    private ForgePullRequestWrites $writes;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $forgePullRequests = $container->get(ForgePullRequestRepository::class);
        self::assertInstanceOf(ForgePullRequestRepository::class, $forgePullRequests);

        $this->merger = new FakePullRequestMerger();
        $this->changer = new FakePullRequestBaseChanger();
        $this->writes = new ForgePullRequestWrites(
            new PullRequestMergers([$this->merger]),
            new PullRequestBaseChangers([$this->changer]),
            $forgePullRequests,
            $em,
            new MockClock(self::NOW),
        );
    }

    public function test_a_merge_marks_the_head_then_asks_the_forge_outside_the_transaction(): void
    {
        $row = $this->row();
        $level = $this->em->getConnection()->getTransactionNestingLevel();
        $seen = null;
        $this->merger->duringMerge = function () use ($row, &$seen): void {
            $seen = [$this->em->getConnection()->getTransactionNestingLevel(), $this->stored($row)];
        };

        $this->writes->merge($row, 'squash');

        self::assertCount(1, $this->merger->merges);
        self::assertSame(['squash', 'head1'], [$this->merger->merges[0][1], $this->merger->merges[0][2]]);
        self::assertSame([$level, ['head1', self::NOW]], $seen);
        self::assertSame(['head1', self::NOW], $this->stored($row));
    }

    public function test_a_failed_merge_clears_its_marker_and_rethrows(): void
    {
        $row = $this->row();
        $failure = new PullRequestWriteFailed('refused', permanent: true);
        $this->merger->failure = $failure;

        try {
            $this->writes->merge($row, 'merge');
            self::fail('Expected PullRequestWriteFailed.');
        } catch (PullRequestWriteFailed $e) {
            self::assertSame($failure, $e);
        }

        self::assertCount(1, $this->merger->merges);
        self::assertSame([null, null], $this->stored($row));
    }

    public function test_a_failed_merge_keeps_a_marker_that_another_request_set(): void
    {
        $row = $this->row();
        $this->merger->failure = new PullRequestWriteFailed('head_moved', permanent: true);
        $this->merger->duringMerge = function () use ($row): void {
            $this->em->wrapInTransaction(function () use ($row): void {
                $locked = $this->locked($row);
                $locked->apply(new PullRequestSnapshot(headSha: 'head2', baseBranch: 'epic/1'));
                $locked->mergeRequestedSha = 'head2';
            });
        };

        try {
            $this->writes->merge($row, 'merge');
            self::fail('Expected PullRequestWriteFailed.');
        } catch (PullRequestWriteFailed) {
        }

        self::assertSame('head2', $this->stored($row)[0]);
    }

    public function test_a_merge_with_no_merger_for_the_forge_fails_and_marks_nothing(): void
    {
        $row = $this->row(forge: 'gitlab');

        $failure = $this->failure(fn () => $this->writes->merge($row, 'merge'));

        self::assertSame('no_writer', $failure->cause);
        self::assertTrue($failure->permanent);
        self::assertSame([null, null], $this->stored($row));
    }

    public function test_a_merge_with_no_known_head_fails_and_asks_nothing(): void
    {
        $row = $this->row(head: null);

        $failure = $this->failure(fn () => $this->writes->merge($row, 'merge'));

        self::assertSame('no_head', $failure->cause);
        self::assertTrue($failure->permanent);
        self::assertSame([], $this->merger->merges);
        self::assertSame([null, null], $this->stored($row));
    }

    public function test_an_unknown_merge_method_is_refused_before_the_marker(): void
    {
        $row = $this->row();

        try {
            $this->writes->merge($row, 'fast-forward');
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame([], $this->merger->merges);
        self::assertSame([null, null], $this->stored($row));
    }

    public function test_a_base_change_marks_the_base_then_asks_the_forge_outside_the_transaction(): void
    {
        $row = $this->row();
        $level = $this->em->getConnection()->getTransactionNestingLevel();
        $seen = null;
        $this->changer->duringChange = function () use ($row, &$seen): void {
            $seen = [$this->em->getConnection()->getTransactionNestingLevel(), $this->storedBase($row)];
        };

        $this->writes->changeBase($row, 'main');

        self::assertCount(1, $this->changer->changes);
        self::assertSame('main', $this->changer->changes[0][1]);
        self::assertSame([$level, ['main', self::NOW]], $seen);
        self::assertSame(['main', self::NOW], $this->storedBase($row));
    }

    public function test_a_failed_base_change_clears_its_marker_and_rethrows(): void
    {
        $row = $this->row();
        $this->changer->failure = new PullRequestWriteFailed('permission', permanent: true);

        $failure = $this->failure(fn () => $this->writes->changeBase($row, 'main'));

        self::assertSame('permission', $failure->cause);
        self::assertSame([null, null], $this->storedBase($row));
    }

    public function test_a_failed_base_change_keeps_a_marker_that_another_request_set(): void
    {
        $row = $this->row();
        $this->changer->failure = new PullRequestWriteFailed('permission', permanent: true);
        $this->changer->duringChange = function () use ($row): void {
            $this->em->wrapInTransaction(function () use ($row): void {
                $this->locked($row)->baseChangeRequestedTo = 'release';
            });
        };

        $this->failure(fn () => $this->writes->changeBase($row, 'main'));

        self::assertSame('release', $this->storedBase($row)[0]);
    }

    public function test_a_base_change_with_no_changer_for_the_forge_fails_and_marks_nothing(): void
    {
        $row = $this->row(forge: 'gitlab');

        $failure = $this->failure(fn () => $this->writes->changeBase($row, 'main'));

        self::assertSame('no_writer', $failure->cause);
        self::assertTrue($failure->permanent);
        self::assertSame([null, null], $this->storedBase($row));
    }

    private function failure(\Closure $call): PullRequestWriteFailed
    {
        try {
            $call();
        } catch (PullRequestWriteFailed $e) {
            return $e;
        }

        self::fail('Expected PullRequestWriteFailed.');
    }

    private function row(string $forge = FakePullRequestStateReader::FORGE, ?string $head = 'head1'): ForgePullRequest
    {
        $owner = new User(fullName: 'Riley', email: 'writes-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($owner, 'writes-'.uniqid());
        $row = new ForgePullRequest($project, $forge, 'acme/widgets', 42);
        $row->apply(new PullRequestSnapshot(headSha: $head, baseBranch: 'epic/1'));
        $this->em->persist($owner);
        $this->em->persist($project);
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }

    private function locked(ForgePullRequest $row): ForgePullRequest
    {
        $repository = self::getContainer()->get(ForgePullRequestRepository::class);
        self::assertInstanceOf(ForgePullRequestRepository::class, $repository);

        return $repository->findForUpdate($row->id ?? throw new \LogicException('A stored row has an id.')) ?? throw new \LogicException('The row exists.');
    }

    /** @return array{?string, ?string} the merge marker and its time, as the database holds them */
    private function stored(ForgePullRequest $row): array
    {
        $stored = $this->em->getConnection()->fetchAssociative('SELECT merge_requested_sha, merge_requested_at FROM forge_pull_requests WHERE id = ?', [$row->id?->toRfc4122()]);
        self::assertIsArray($stored);

        return [$stored['merge_requested_sha'], $stored['merge_requested_at']];
    }

    /** @return array{?string, ?string} the base marker and its time, as the database holds them */
    private function storedBase(ForgePullRequest $row): array
    {
        $stored = $this->em->getConnection()->fetchAssociative('SELECT base_change_requested_to, base_change_requested_at FROM forge_pull_requests WHERE id = ?', [$row->id?->toRfc4122()]);
        self::assertIsArray($stored);

        return [$stored['base_change_requested_to'], $stored['base_change_requested_at']];
    }
}
