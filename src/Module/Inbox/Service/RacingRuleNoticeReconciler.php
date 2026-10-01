<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Board\Command\RacingBridgeRuleView;
use App\Module\Board\Repository\BridgeRuleReportRepository;
use App\Module\Board\Service\RacingBridgeRules;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\InboxEventType;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Keeps one open notice while a bridge rule races the app sync, and closes it
 * when none does. Running it twice changes nothing, so any trigger may ask again.
 */
final readonly class RacingRuleNoticeReconciler
{
    public function __construct(
        private EntityManagerInterface $em,
        private BridgeRuleReportRepository $bridgeRuleReports,
        private RacingBridgeRules $racingBridgeRules,
        private InboxItemRepository $inboxItems,
        private InboxItemCloser $closer,
        private InboxSearchIndexer $searchIndexer,
        private InboxOpenCountPublisher $openCount,
        private InboxAvailability $inbox,
    ) {
    }

    public function reconcile(Project $project): void
    {
        $countChanged = $this->em->wrapInTransaction(function () use ($project): bool {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

            $enabled = $this->inbox->isEnabled();
            $racing = $enabled ? $this->racingRules($project) : [];
            $notice = $this->inboxItems->findOpenNotice($project);
            $now = new \DateTimeImmutable();

            if ([] === $racing) {
                if (null === $notice) {
                    return false;
                }
                $this->closer->close($notice, $enabled ? InboxItemState::Done : InboxItemState::Obsolete, null, $now, InboxEventType::ACTOR_AGENT);
                $this->em->flush();

                return true;
            }

            $title = self::title($racing);
            $body = self::body($racing);
            if (null === $notice) {
                $notice = new InboxItem(
                    project: $project,
                    number: $this->inboxItems->nextNumber($project),
                    kind: InboxItemKind::Notice,
                    title: $title,
                    blocking: false,
                    body: $body,
                    createdAt: $now,
                    searchLanguage: $project->searchLanguage,
                );
                $this->em->persist($notice);
                $opened = true;
            } elseif ($notice->title !== $title || $notice->body !== $body) {
                $notice->title = $title;
                $notice->body = $body;
                $notice->updatedAt = $now;
                $opened = false;
            } else {
                return false;
            }

            $this->em->flush();
            $this->searchIndexer->index($notice);

            return $opened;
        });

        if ($countChanged) {
            $this->openCount->countChanged($project);
        }
    }

    /** @return list<RacingBridgeRuleView> by bridge, then by name, so the body reads the same on every run */
    private function racingRules(Project $project): array
    {
        $racing = $this->racingBridgeRules->forProject($project, $this->bridgeRuleReports->findForProject($project));
        usort($racing, static fn (RacingBridgeRuleView $a, RacingBridgeRuleView $b): int => [$a->bridgeId, $a->name] <=> [$b->bridgeId, $b->name]);

        return $racing;
    }

    /** @param non-empty-list<RacingBridgeRuleView> $racing */
    private static function title(array $racing): string
    {
        return 1 === \count($racing) ? 'A bridge rule races the app sync' : \sprintf('%d bridge rules race the app sync', \count($racing));
    }

    /** @param non-empty-list<RacingBridgeRuleView> $racing */
    private static function body(array $racing): string
    {
        $lines = array_map(
            static fn (RacingBridgeRuleView $rule): string => \sprintf('- `%s` on bridge `%s`', $rule->name, mb_substr($rule->bridgeId, 0, 8)),
            $racing,
        );

        return implode("\n", $lines)."\n\nThe app syncs a pull request that is behind. Remove each rule on `pull_request.behind` from rules.yaml.";
    }
}
