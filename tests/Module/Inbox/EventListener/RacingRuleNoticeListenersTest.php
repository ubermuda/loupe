<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\EventListener;

use App\Module\Board\Command\SaveBoardAutomationSettingsCommand;
use App\Module\Board\Command\SaveBoardAutomationSettingsHandler;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\BoardFixStrategy;
use App\Module\Board\Entity\BoardMergeStrategy;
use App\Module\Board\Entity\BridgeRuleReport;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Controller\BoardScenario;
use App\Tests\Module\Inbox\InboxScenario;
use App\Tests\Support\AgentCredential;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** A bridge report and a settings save each bring the racing rule notice up to date. */
final class RacingRuleNoticeListenersTest extends WebTestCase
{
    use BoardScenario;
    use InboxScenario;

    private const array RACING_RULE = ['name' => 'sync-behind', 'on' => 'pull_request.behind', 'columns' => [], 'state' => 'live', 'reason' => null];

    public function test_a_report_with_a_racing_rule_opens_the_notice(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'racing-report@example.com');
        $project = $this->project($em, $owner, 'Racing Report App');
        $this->syncBehind($em, $project, true);
        $this->enableBoard();
        $this->setInboxFlag(true);
        $raw = AgentCredential::agentToken(static::getContainer(), $owner);

        $client->request(Request::METHOD_PUT, '/api/projects/'.$project->id.'/bridges/'.Uuid::v4().'/rules', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['rules' => [self::RACING_RULE]], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(204);
        self::assertSame([InboxItemState::Open], $this->noticeStates($this->em(), $project));
    }

    public function test_turning_the_sync_off_closes_the_notice_and_on_again_opens_one(): void
    {
        static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'racing-settings@example.com');
        $project = $this->project($em, $owner, 'Racing Settings App');
        $this->setInboxFlag(true);
        $em->persist(new BridgeRuleReport($project, Uuid::v4(), [self::RACING_RULE]));
        $em->flush();

        $this->save($project, syncBehind: true);
        self::assertSame([InboxItemState::Open], $this->noticeStates($em, $project));

        $this->save($project, syncBehind: false);
        self::assertSame([InboxItemState::Done], $this->noticeStates($em, $project));

        $this->save($project, syncBehind: true);
        self::assertSame([InboxItemState::Done, InboxItemState::Open], $this->noticeStates($em, $project));
    }

    private function save(Project $project, bool $syncBehind): void
    {
        $handler = static::getContainer()->get(SaveBoardAutomationSettingsHandler::class);
        self::assertInstanceOf(SaveBoardAutomationSettingsHandler::class, $handler);
        $handler(new SaveBoardAutomationSettingsCommand(
            project: $project,
            enabled: true,
            mergeStrategy: BoardMergeStrategy::Worker,
            fixStrategy: BoardFixStrategy::Fresh,
            loopLimit: 3,
            commentOnFixQueued: false,
            commentOnStaleApproval: false,
            syncBehind: $syncBehind,
        ));
    }

    private function syncBehind(EntityManagerInterface $em, Project $project, bool $on): void
    {
        $settings = new BoardAutomationSettings($project);
        $settings->syncBehind = $on;
        $em->persist($settings);
        $em->flush();
    }

    /** @return list<InboxItemState> oldest first */
    private function noticeStates(EntityManagerInterface $em, Project $project): array
    {
        $states = $em->getConnection()->fetchFirstColumn(
            'SELECT state FROM inbox_items WHERE project_id = :project AND kind = :kind ORDER BY number',
            ['project' => (string) $project->id, 'kind' => InboxItemKind::Notice->value],
        );

        return array_map(static fn (mixed $state): InboxItemState => InboxItemState::from((string) $state), $states);
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
