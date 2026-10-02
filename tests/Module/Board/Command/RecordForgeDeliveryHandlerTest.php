<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\RecordForgeDeliveryCommand;
use App\Module\Board\Command\RecordForgeDeliveryHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Entity\PullRequestComment;
use App\Module\Board\Entity\PullRequestCommentState;
use App\Module\Board\Entity\PullRequestNotice;
use App\Module\Forge\ForgeDelivery;
use App\Module\Forge\ForgeEventType;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** Two projects can link one pull request, and a delivery belongs to the project that owns the repository. */
final class RecordForgeDeliveryHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_a_delivery_reaches_only_the_cards_of_the_owning_project(): void
    {
        $owner = $this->project('owner');
        $stranger = $this->project('stranger');
        $ownCard = $this->linkedCard($owner, 'acme/widgets', 5);
        $this->linkedCard($stranger, 'acme/widgets', 5);

        $this->handle($owner, new ForgeDelivery(ForgeEventType::MERGED, 'github', 'ACME/widgets', 5));

        self::assertSame([(string) $ownCard->id], $this->outboxSubjects($owner));
        self::assertSame([], $this->outboxSubjects($stranger));
    }

    public function test_a_move_repoints_only_the_links_of_the_owning_project(): void
    {
        $owner = $this->project('owner');
        $stranger = $this->project('stranger');
        $ownCard = $this->linkedCard($owner, 'acme/old', 1);
        $strangerCard = $this->linkedCard($stranger, 'acme/old', 1);

        $this->handle($owner, new ForgeDelivery(ForgeEventType::REPOSITORY_MOVED, 'github', 'acme/old', movedTo: 'acme/new'));

        $this->em->clear();
        self::assertSame(['acme/new'], $this->pathsOf($ownCard));
        self::assertSame(['acme/old'], $this->pathsOf($strangerCard));
    }

    public function test_a_move_repoints_the_unposted_fix_run_comments_of_the_owning_project(): void
    {
        $owner = $this->project('owner');
        $stranger = $this->project('stranger');
        $pending = $this->comment($owner, 'github', 'Acme/Old', PullRequestCommentState::Pending);
        $failed = $this->comment($owner, 'github', 'acme/old', PullRequestCommentState::Failed);
        $posted = $this->comment($owner, 'github', 'acme/old', PullRequestCommentState::Posted);
        $otherForge = $this->comment($owner, 'gitlab', 'acme/old', PullRequestCommentState::Pending);
        $strangers = $this->comment($stranger, 'github', 'acme/old', PullRequestCommentState::Pending);

        $this->handle($owner, new ForgeDelivery(ForgeEventType::REPOSITORY_MOVED, 'github', 'acme/old', movedTo: 'acme/new'));

        self::assertSame('acme/new', $this->pathOf($pending));
        self::assertSame('acme/new', $this->pathOf($failed));
        self::assertSame('acme/old', $this->pathOf($posted));
        self::assertSame('acme/old', $this->pathOf($otherForge));
        self::assertSame('acme/old', $this->pathOf($strangers));
    }

    public function test_a_move_repoints_every_stale_approval_notice_and_keeps_one_per_key(): void
    {
        $owner = $this->project('notice-owner');
        $posted = $this->notice($owner, 'acme/old', 5, 'stale-approval:aaa');
        $collides = $this->notice($owner, 'acme/old', 6, 'stale-approval:bbb');
        $existing = $this->notice($owner, 'acme/new', 6, 'stale-approval:bbb');
        $otherKey = $this->notice($owner, 'acme/old', 6, 'stale-approval:ccc');

        $this->handle($owner, new ForgeDelivery(ForgeEventType::REPOSITORY_MOVED, 'github', 'Acme/Old', movedTo: 'Acme/New'));

        $paths = $this->em->getConnection()->fetchAllKeyValue('SELECT id, repository FROM board_pull_request_notices WHERE project_id = :p', ['p' => (string) $owner->id]);
        self::assertSame('acme/new', $paths[(string) $posted->id]);
        self::assertArrayNotHasKey((string) $collides->id, $paths);
        self::assertSame('acme/new', $paths[(string) $existing->id]);
        self::assertSame('acme/new', $paths[(string) $otherKey->id]);
    }

    public function test_a_move_that_collides_keeps_the_post_of_the_old_path(): void
    {
        $owner = $this->project('notice-collision');
        $this->notice($owner, 'acme/old', 6, 'stale-approval:bbb');
        $pending = $this->notice($owner, 'acme/new', 6, 'stale-approval:bbb', PullRequestCommentState::Pending);

        $this->handle($owner, new ForgeDelivery(ForgeEventType::REPOSITORY_MOVED, 'github', 'acme/old', movedTo: 'acme/new'));

        $this->em->clear();
        $survivor = $this->em->find(PullRequestNotice::class, $pending->id);
        self::assertNotNull($survivor);
        self::assertSame(PullRequestCommentState::Posted, $survivor->state);
    }

    public function test_a_delivery_without_state_reads_writes_a_bare_fact_row_and_one_with_state_reads_does_not(): void
    {
        $project = $this->project('installed');
        $card = $this->linkedCard($project, 'acme/widgets', 5);
        $this->handle($project, new ForgeDelivery(ForgeEventType::MERGED, 'github', 'acme/widgets', 5));
        self::assertSame([(string) $card->id], $this->outboxSubjects($project));

        foreach ([ForgeEventType::MERGED, ForgeEventType::REVIEW_SUBMITTED, ForgeEventType::CHECKS_CONCLUDED] as $type) {
            $this->handle($project, new ForgeDelivery($type, 'github', 'acme/widgets', 5), stateReadable: true);
        }

        self::assertSame([(string) $card->id], $this->outboxSubjects($project));
    }

    public function test_a_move_with_state_reads_still_repoints_the_links(): void
    {
        $project = $this->project('installed');
        $card = $this->linkedCard($project, 'acme/old', 1);

        $this->handle($project, new ForgeDelivery(ForgeEventType::REPOSITORY_MOVED, 'github', 'acme/old', movedTo: 'acme/new'), stateReadable: true);

        $this->em->clear();
        self::assertSame(['acme/new'], $this->pathsOf($card));
    }

    private function handle(Project $project, ForgeDelivery $delivery, bool $stateReadable = false): void
    {
        $handler = self::getContainer()->get(RecordForgeDeliveryHandler::class);
        self::assertInstanceOf(RecordForgeDeliveryHandler::class, $handler);

        $handler(new RecordForgeDeliveryCommand($project->id ?? throw new \LogicException('Flushed.'), [$delivery], $stateReadable));
    }

    /** @return list<string> */
    private function outboxSubjects(Project $project): array
    {
        $rows = $this->em->getConnection()->fetchFirstColumn(
            'SELECT payload FROM outbox_events WHERE project_id = :project',
            ['project' => $project->id],
            ['project' => 'uuid'],
        );

        return array_map(static fn (mixed $payload): string => json_decode((string) $payload, true, flags: \JSON_THROW_ON_ERROR)['subject']['id'], $rows);
    }

    /** @return list<string> */
    private function pathsOf(Card $card): array
    {
        return array_values(array_map(
            strval(...),
            $this->em->getConnection()->fetchFirstColumn(
                'SELECT repository FROM board_card_pull_requests WHERE card_id = :card',
                ['card' => $card->id],
                ['card' => 'uuid'],
            ),
        ));
    }

    private function comment(Project $project, string $forge, string $repository, PullRequestCommentState $state): PullRequestComment
    {
        $comment = new PullRequestComment($project, Uuid::v7(), Uuid::v7(), $forge, $repository, 1, null, 'conflict');
        $comment->state = $state;
        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }

    private function pathOf(PullRequestComment $comment): string
    {
        return (string) $this->em->getConnection()->fetchOne(
            'SELECT repository FROM board_pull_request_comments WHERE id = :id',
            ['id' => $comment->id],
            ['id' => 'uuid'],
        );
    }

    private function linkedCard(Project $project, string $repository, int $number): Card
    {
        $column = new BoardColumn($project, 'Work', 'work-'.uniqid(), 0);
        $card = new Card($project, $column, 'Ship it', '', random_int(1, 1_000_000));
        $link = new CardPullRequest($card, 'https://github.com/'.$repository.'/pull/'.$number, Forge::GitHub, $repository, $number);
        foreach ([$column, $card, $link] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        return $card;
    }

    private function project(string $label): Project
    {
        $user = new User(fullName: 'Riley', email: 'record-'.$label.'-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($user, $label.'-'.uniqid());
        $this->em->persist($user);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    private function notice(Project $project, string $repository, int $number, string $key, PullRequestCommentState $state = PullRequestCommentState::Posted): PullRequestNotice
    {
        $notice = new PullRequestNotice($project, Uuid::v7(), 'github', $repository, $number, $key);
        $notice->state = $state;
        $this->em->persist($notice);
        $this->em->flush();

        return $notice;
    }
}
