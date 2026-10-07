<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Entity\WorkflowBinding;
use App\Module\Workflow\Template\AppRules;
use App\Module\Workflow\Template\TemplateParser;
use App\Tests\Module\Workflow\Template\AppRulesTest;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\AcceptedTerms;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowWorkflowSettingsControllerTest extends WebTestCase
{
    use WorkflowProjects;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function test_a_lifecycle_project_shows_its_template_slots_and_rules(): void
    {
        $project = $this->workflowProject('workflow-page-lifecycle');
        $this->bindLifecycle($project);
        $owner = $this->stampedOwner($project);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/workflow');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-workflow-template]', 'Lifecycle');
        self::assertSame(['next', 'product-design', 'tech-design', 'implementation', 'in-review'], $crawler->filter('[data-slot-key]')->extract(['data-slot-key']));
        self::assertSelectorTextContains('[data-slot-key="tech-design"]', 'Tech design');
        self::assertSelectorTextContains('[data-slot-key="implementation"] [data-slot-column]', 'In progress');
        self::assertSelectorTextContains('[data-rule-id="product-design-approved"]', 'Move the card to Tech design');
        self::assertSelectorTextContains('[data-rule-id="implement"]', 'implement');
        self::assertSelectorTextContains('[data-rule-id="implement"] [data-condition-source="workflow.source.board"]', 'Board: not card.type (type: epic)');
        self::assertSelectorTextContains('[data-rule-id="implement"] [data-condition-source="workflow.source.forge"]', 'Forge: not pr.linked');
        self::assertSelectorTextContains('[data-rule-id="merged"] [data-condition-source="workflow.source.board"]', 'Board: not card.in_slot (slot: @terminal), card.children_finished');
        self::assertSelectorNotExists('[data-rule-missing]');
        self::assertSelectorTextContains('[data-workflow-app-rules] [data-rule-id="discovery"] [data-condition-source="workflow.source.readiness"]', 'Readiness: card.discovery_requested');
        self::assertSelectorNotExists('[data-workflow-template-rules] [data-rule-id="discovery"]');
        self::assertSelectorExists('[data-manual-move]');
        self::assertSame(['Anyone', 'A run of the parent epic'], array_values(array_unique($crawler->filter('[data-manual-move-by]')->extract(['_text']))));
        self::assertSame(['feature', 'bug', 'security', 'tooling', 'docs', 'idea', 'epic'], $crawler->filter('[data-workflow-types] [data-card-type]')->extract(['data-card-type']));
        self::assertSelectorTextContains('[data-workflow-types] [data-card-type="feature"]', 'Feature');
        self::assertSelectorTextContains('[data-workflow-types] [data-card-type="feature"]', 'Default');
        self::assertSelectorTextNotContains('[data-workflow-types] [data-card-type="bug"]', 'Default');
        self::assertSelectorExists('[data-workflow-types] [data-card-type="bug"] .lp-tag--amber');
        self::assertSelectorTextSame('[data-workflow-types] [data-card-type="bug"] [data-card-type-capabilities]', 'None');
        self::assertSelectorTextSame('[data-workflow-types] [data-card-type="epic"] [data-card-type-capabilities]', 'Can have children, Gets a lane');
        self::assertSelectorTextContains('[data-workflow-timings]', '10, 60, 360');
        self::assertSelectorTextContains('[data-workflow-timings]', '120');
        self::assertSelectorTextContains('[data-workflow-timings]', 'Retries after failed work: 2, 3, 5 minutes');
        self::assertCount(1, $crawler->filter('main [data-workflow-template]'));
        self::assertCount(0, $crawler->filter('main form, main button'));
        self::assertSelectorExists('a.lp-sidebar__link--active[href="/projects/'.$project->id.'/workflow"]');
    }

    public function test_a_deleted_column_reads_as_not_linked(): void
    {
        $project = $this->workflowProject('workflow-page-deleted');
        $this->bindLifecycle($project);
        $this->em()->remove($this->column($project, 'tech-design'));
        $owner = $this->stampedOwner($project);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/workflow');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-slot-key="tech-design"] [data-slot-column]', 'Not linked');
    }

    public function test_a_rule_whose_condition_no_longer_exists_is_marked(): void
    {
        $project = $this->workflowProject('workflow-page-missing');
        $this->em()->persist(new WorkflowBinding($project, 'test', 1, [
            'key' => 'test',
            'version' => 1,
            'defaultType' => 'feature',
            'types' => [
                ['key' => 'feature', 'label' => 'board.card.type.feature', 'tone' => 'lime'],
                ['key' => 'bug', 'label' => 'board.card.type.bug', 'tone' => 'amber'],
                ['key' => 'security', 'label' => 'board.card.type.security', 'tone' => 'red'],
                ['key' => 'tooling', 'label' => 'board.card.type.tooling', 'tone' => 'neutral'],
                ['key' => 'docs', 'label' => 'board.card.type.docs', 'tone' => 'green'],
                ['key' => 'idea', 'label' => 'board.card.type.idea', 'tone' => 'purple'],
                ['key' => 'epic', 'label' => 'board.card.type.epic', 'tone' => 'blue', 'capabilities' => ['children', 'lane']],
            ],
            'slots' => [],
            'manualMoves' => [],
            'backoffMinutes' => [10],
            'workTimeoutMinutes' => 120,
            'rules' => [
                ['id' => 'gone', 'when' => ['all' => [['card.gone' => []], ['card.is_child' => []]]], 'then' => ['request' => ['kind' => 'gone']]],
                ['id' => 'hold', 'when' => ['card.is_child' => []], 'then' => ['pause' => ['reason' => 'held', 'until' => ['all' => [['card.is_child' => []], ['pr.open' => []]]]]]],
            ],
        ]));
        $owner = $this->stampedOwner($project);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/workflow');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-rule-id="gone"][data-rule-missing] .lp-tag', 'Condition no longer exists: card.gone');
        self::assertSelectorTextContains('[data-rule-id="gone"] [data-condition-source="workflow.source.board"]', 'Board: card.is_child');
        self::assertSelectorNotExists('[data-rule-id="gone"] [data-rule-until]');
        self::assertSelectorTextContains('[data-rule-id="hold"] [data-rule-until]', 'Until:');
        self::assertSelectorTextContains('[data-rule-id="hold"] [data-rule-until] [data-condition-source="workflow.source.board"]', 'Board: card.is_child');
        self::assertSelectorTextContains('[data-rule-id="hold"] [data-rule-until] [data-condition-source="workflow.source.forge"]', 'Forge: pr.open');
        self::assertCount(2, $crawler->filter('[data-rule-id="hold"] [data-condition-source="workflow.source.board"]'));
    }

    public function test_the_rules_the_app_adds_show_in_their_own_group(): void
    {
        $parser = self::getContainer()->get(TemplateParser::class);
        self::assertInstanceOf(TemplateParser::class, $parser);
        self::getContainer()->set(AppRules::class, new AppRules($parser, AppRulesTest::FIXTURE));
        $project = $this->workflowProject('workflow-page-app-rules');
        $this->bindLifecycle($project);
        $owner = $this->stampedOwner($project);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/workflow');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-workflow-app-rules] h2', 'Rules the app adds');
        self::assertSelectorTextContains('[data-workflow-app-rules] [data-rule-id="app-groom"]', 'Backlog');
        self::assertSelectorTextContains('[data-workflow-app-rules] [data-rule-id="app-groom"]', 'groom');
        self::assertSelectorExists('[data-workflow-app-rules] [data-rule-id="app-tidy"]');
        self::assertSelectorNotExists('[data-workflow-app-rules] [data-rule-id="implement"]');
        self::assertSelectorNotExists('[data-workflow-template-rules] [data-rule-id="app-groom"]');
        self::assertSelectorExists('[data-workflow-template-rules] [data-rule-id="implement"]');
    }

    public function test_an_unbound_project_says_it_runs_no_template(): void
    {
        $project = $this->workflowProject('workflow-page-unbound');
        $owner = $this->stampedOwner($project);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/workflow');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-workflow-unbound]', 'This project runs no workflow template yet.');
        self::assertSelectorNotExists('[data-slot-key]');
    }

    public function test_a_user_outside_the_project_is_refused(): void
    {
        $project = $this->workflowProject('workflow-page-stranger');
        $this->bindLifecycle($project);
        $stranger = new User(fullName: 'Stranger', email: 'workflow-stranger-'.uniqid().'@example.com', password: 'x');
        $stranger->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($stranger, self::getContainer());
        $this->em()->persist($stranger);
        $this->em()->flush();
        $this->em()->clear();

        $this->client->loginUser($stranger);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/workflow');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_a_rule_names_the_column_flags_without_a_column_label(): void
    {
        $project = $this->workflowProject('workflow-page-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'simple', []));
        $owner = $this->stampedOwner($project);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/workflow');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-rule-id="merged"]', 'Move the card to a terminal column');
    }

    public function test_the_sidebar_links_the_page(): void
    {
        $project = $this->workflowProject('workflow-page-sidebar');
        $owner = $this->stampedOwner($project);
        $this->client->loginUser($owner);
        $link = 'a.lp-sidebar__link[href="/projects/'.$project->id.'/workflow"]';

        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/documents');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains($link, 'Workflow');
    }

    private function stampedOwner(Project $project): User
    {
        $owner = $project->owner;
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, self::getContainer());
        $this->em()->flush();
        $this->em()->clear();

        return $owner;
    }
}
