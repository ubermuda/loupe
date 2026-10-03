<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\BridgeRuleReport;
use App\Module\Board\Repository\BridgeRuleReportRepository;
use App\Module\Bridge\Command\RecordBridgeHeartbeatCommand;
use App\Module\Bridge\Command\RecordBridgeHeartbeatHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\RacingRuleNoticeReconciler;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RacingRuleNoticeReconcilerTest extends KernelTestCase
{
    use InboxFixtures;

    private const string BRIDGE = '01990000-0000-7000-8000-00000000abcd';

    private EntityManagerInterface $em;
    private RacingRuleNoticeReconciler $reconciler;
    private Project $project;
    private BoardAutomationSettings $settings;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $reconciler = self::getContainer()->get(RacingRuleNoticeReconciler::class);
        self::assertInstanceOf(RacingRuleNoticeReconciler::class, $reconciler);
        $this->reconciler = $reconciler;

        $this->project = $this->project($em, $this->owner($em, 'racing-notice'), 'racing-notice');
        $this->settings = new BoardAutomationSettings($this->project);
        $this->settings->syncBehind = true;
        $em->persist($this->settings);
        $em->flush();
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
    }

    public function test_a_racing_rule_opens_one_loose_notice_from_loupe(): void
    {
        $this->report(['sync-behind']);

        $this->reconciler->reconcile($this->project);

        $item = $this->onlyNotice();
        self::assertSame(InboxItemState::Open, $item->state);
        self::assertFalse($item->blocking);
        self::assertSame(InboxAskOrigin::Loupe, $item->origin);
        self::assertSame([], $item->options);
        self::assertSame(1, $item->number);
        self::assertSame('A bridge rule races the app sync', $item->title);
        self::assertSame(
            "- `sync-behind` on bridge `00000000abcd`\n\nThe app syncs a pull request that is behind. Remove each rule on `pull_request.behind` from rules.yaml.",
            $item->body,
        );
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM '.$this->em->getClassMetadata(InboxAskItem::class)->getTableName().' WHERE item_id = :item',
            ['item' => (string) $item->id],
        ));
        self::assertSame(1, $this->searchHits('races'));
    }

    public function test_the_notice_names_a_bridge_that_holds_a_name(): void
    {
        $bridge = new Bridge($this->project->owner, Uuid::fromString(self::BRIDGE), [(string) $this->project->id], 'b4e39aa7', new \DateTimeImmutable());
        $bridge->name = 'homelab';
        $this->em->persist($bridge);
        $this->report(['sync-behind']);

        $this->reconciler->reconcile($this->project);

        self::assertStringStartsWith("- `sync-behind` on bridge `homelab`\n\n", (string) $this->onlyNotice()->body);
    }

    public function test_a_heartbeat_that_changes_the_name_rewrites_the_open_notice(): void
    {
        $this->report(['sync-behind']);
        $this->reconciler->reconcile($this->project);
        $heartbeat = self::getContainer()->get(RecordBridgeHeartbeatHandler::class);
        self::assertInstanceOf(RecordBridgeHeartbeatHandler::class, $heartbeat);

        $heartbeat(new RecordBridgeHeartbeatCommand($this->project->owner, Uuid::fromString(self::BRIDGE), [(string) $this->project->id], 'b4e39aa7', name: 'homelab'));

        self::assertStringStartsWith("- `sync-behind` on bridge `homelab`\n\n", (string) $this->onlyNotice()->body);
    }

    public function test_a_rename_rewrites_the_notice_of_a_project_the_bridge_stopped_following(): void
    {
        $this->report(['sync-behind']);
        $this->reconciler->reconcile($this->project);
        $heartbeat = self::getContainer()->get(RecordBridgeHeartbeatHandler::class);
        self::assertInstanceOf(RecordBridgeHeartbeatHandler::class, $heartbeat);
        $heartbeat(new RecordBridgeHeartbeatCommand($this->project->owner, Uuid::fromString(self::BRIDGE), [(string) $this->project->id], 'b4e39aa7'));

        $heartbeat(new RecordBridgeHeartbeatCommand($this->project->owner, Uuid::fromString(self::BRIDGE), [], 'b4e39aa7', name: 'homelab'));

        self::assertStringStartsWith("- `sync-behind` on bridge `homelab`\n\n", (string) $this->onlyNotice()->body);
    }

    public function test_a_backtick_in_a_bridge_name_cannot_close_its_code_span(): void
    {
        $bridge = new Bridge($this->project->owner, Uuid::fromString(self::BRIDGE), [(string) $this->project->id], 'b4e39aa7', new \DateTimeImmutable());
        $bridge->name = 'a` [Open](https://example.com) `b';
        $this->em->persist($bridge);
        $this->report(['sync-behind']);

        $this->reconciler->reconcile($this->project);

        self::assertStringStartsWith("- `sync-behind` on bridge `` a` [Open](https://example.com) `b ``\n\n", (string) $this->onlyNotice()->body);
    }

    public function test_a_second_reconcile_changes_nothing(): void
    {
        $this->report(['sync-behind']);
        $this->reconciler->reconcile($this->project);
        $updatedAt = $this->onlyNotice()->updatedAt;

        $this->reconciler->reconcile($this->project);

        self::assertEquals($updatedAt, $this->onlyNotice()->updatedAt);
    }

    public function test_a_new_racing_rule_rewrites_the_open_notice(): void
    {
        $this->report(['sync-behind']);
        $this->reconciler->reconcile($this->project);
        $this->report(['sync-behind', 'rebase']);

        $this->reconciler->reconcile($this->project);

        $item = $this->onlyNotice();
        self::assertSame(InboxItemState::Open, $item->state);
        self::assertSame('2 bridge rules race the app sync', $item->title);
        self::assertStringStartsWith("- `rebase` on bridge `00000000abcd`\n- `sync-behind` on bridge `00000000abcd`\n\n", (string) $item->body);
    }

    public function test_the_notice_closes_as_done_when_no_rule_races(): void
    {
        $this->report(['sync-behind']);
        $this->reconciler->reconcile($this->project);
        $this->report([]);

        $this->reconciler->reconcile($this->project);

        $item = $this->onlyNotice();
        self::assertSame(InboxItemState::Done, $item->state);
        self::assertNotNull($item->closedAt);
    }

    public function test_the_notice_closes_when_the_sync_turns_off(): void
    {
        $this->report(['sync-behind']);
        $this->reconciler->reconcile($this->project);
        $this->settings->syncBehind = false;
        $this->em->flush();

        $this->reconciler->reconcile($this->project);

        self::assertSame(InboxItemState::Done, $this->onlyNotice()->state);
    }

    public function test_a_racing_rule_after_a_closed_notice_opens_a_new_one(): void
    {
        $this->report(['sync-behind']);
        $this->reconciler->reconcile($this->project);
        $this->report([]);
        $this->reconciler->reconcile($this->project);
        $this->report(['sync-behind']);

        $this->reconciler->reconcile($this->project);

        $notices = $this->notices();
        self::assertCount(2, $notices);
        self::assertSame([InboxItemState::Done, InboxItemState::Open], array_map(static fn (InboxItem $item): InboxItemState => $item->state, $notices));
    }

    public function test_the_sync_off_opens_nothing(): void
    {
        $this->settings->syncBehind = false;
        $this->em->flush();
        $this->report(['sync-behind']);

        $this->reconciler->reconcile($this->project);

        self::assertSame([], $this->notices());
    }

    public function test_the_inbox_off_opens_nothing(): void
    {
        $this->switchFlag($this->em, InboxInstallFlags::FLAG_INBOX_ENABLED, false);
        $this->report(['sync-behind']);

        $this->reconciler->reconcile($this->project);

        self::assertSame([], $this->notices());
    }

    public function test_the_inbox_off_closes_an_open_notice_as_obsolete(): void
    {
        $this->report(['sync-behind']);
        $this->reconciler->reconcile($this->project);
        $this->switchFlag($this->em, InboxInstallFlags::FLAG_INBOX_ENABLED, false);

        $this->reconciler->reconcile($this->project);

        self::assertSame(InboxItemState::Obsolete, $this->onlyNotice()->state);
    }

    /** @param list<string> $names live rules on pull_request.behind */
    private function report(array $names): void
    {
        $rules = array_map(static fn (string $name): array => ['name' => $name, 'on' => 'pull_request.behind', 'columns' => [], 'state' => BridgeRuleReport::STATE_LIVE, 'reason' => null], $names);
        $rules[] = ['name' => 'plan', 'on' => 'board.card_moved', 'columns' => ['ready'], 'state' => BridgeRuleReport::STATE_LIVE, 'reason' => null];

        $reports = self::getContainer()->get(BridgeRuleReportRepository::class);
        self::assertInstanceOf(BridgeRuleReportRepository::class, $reports);
        $report = $reports->findOneByProjectAndBridge($this->project, Uuid::fromString(self::BRIDGE));
        if (null === $report) {
            $this->em->persist(new BridgeRuleReport($this->project, Uuid::fromString(self::BRIDGE), $rules));
        } else {
            $report->rules = $rules;
        }
        $this->em->flush();
    }

    private function onlyNotice(): InboxItem
    {
        $notices = $this->notices();
        self::assertCount(1, $notices);

        return $notices[0];
    }

    /** @return list<InboxItem> oldest first, the same objects the reconciler wrote */
    private function notices(): array
    {
        $items = self::getContainer()->get(InboxItemRepository::class);
        self::assertInstanceOf(InboxItemRepository::class, $items);

        return array_values($items->findBy(['project' => $this->project, 'kind' => InboxItemKind::Notice], ['number' => 'ASC']));
    }

    private function searchHits(string $word): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM inbox_items WHERE project_id = :project AND search_vector @@ plainto_tsquery('english', :word)",
            ['project' => (string) $this->project->id, 'word' => $word],
        );
    }
}
