<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\AcceptedTerms;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

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
        $this->enableBoard();
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
        self::assertSelectorExists('[data-manual-move]');
        self::assertSelectorTextContains('[data-workflow-timings]', '10, 60, 360');
        self::assertSelectorTextContains('[data-workflow-timings]', '120');
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

    public function test_the_sidebar_links_the_page_only_when_the_board_is_on(): void
    {
        $project = $this->workflowProject('workflow-page-sidebar');
        $owner = $this->stampedOwner($project);
        $this->client->loginUser($owner);
        $link = 'a.lp-sidebar__link[href="/projects/'.$project->id.'/workflow"]';

        $this->enableBoard();
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/rules');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains($link, 'Workflow');

        $this->enableBoard(false);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/documents');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists($link);
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

    private function enableBoard(bool $enabled = true): void
    {
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()['board.enabled']->value = $enabled;
        $this->em()->flush();
    }
}
