<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\PullRequestComment;
use App\Module\Board\Entity\PullRequestCommentState;
use App\Module\Board\Repository\BoardAutomationSettingsRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class EditBoardAutomationSettingsControllerTest extends WebTestCase
{
    use BoardScenario;

    private const string FORM = 'save_board_automation_settings_form';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_the_owner_sees_the_defaults_saves_and_reads_them_back(): void
    {
        $project = $this->ownedProject('automation-save@example.com');
        $crawler = $this->page($project);

        self::assertSelectorExists('.lp-settings-nav__item--active[href$="/settings/automation"]');
        $form = $crawler->filter('form[name="'.self::FORM.'"]');
        self::assertCount(1, $form->filter('input[name="'.self::FORM.'[enabled]"]:checked'));
        self::assertCount(0, $form->filter('[name="'.self::FORM.'[mergeStrategy]"], [name="'.self::FORM.'[fixStrategy]"], [name="'.self::FORM.'[loopLimit]"]'));
        self::assertCount(1, $form->filter('input[name="'.self::FORM.'[commentOnFixQueued]"]'));
        self::assertCount(0, $form->filter('input[name="'.self::FORM.'[commentOnFixQueued]"]:checked'));
        self::assertCount(1, $form->filter('input[name="'.self::FORM.'[commentOnStaleApproval]"]'));
        self::assertCount(0, $form->filter('input[name="'.self::FORM.'[commentOnStaleApproval]"]:checked'));
        self::assertCount(1, $form->filter('input[name="'.self::FORM.'[syncBehind]"]'));
        self::assertCount(0, $form->filter('input[name="'.self::FORM.'[syncBehind]"]:checked'));
        self::assertCount(1, $form->filter('input[name="'.self::FORM.'[mergePullRequests]"]'));
        self::assertCount(0, $form->filter('input[name="'.self::FORM.'[mergePullRequests]"]:checked'));
        self::assertCount(1, $form->filter('input[name="'.self::FORM.'[changeBase]"]'));
        self::assertCount(0, $form->filter('input[name="'.self::FORM.'[changeBase]"]:checked'));
        self::assertCount(1, $form->filter('input[name="'.self::FORM.'[epicDraftSwitch]"]'));
        self::assertCount(1, $form->filter('input[name="'.self::FORM.'[closeEpicPullRequests]"]'));
        self::assertCount(0, $form->filter('input[name="'.self::FORM.'[openEpicPullRequests]"]:checked'));
        self::assertCount(1, $form->filter('input[name="'.self::FORM.'[postWidgetReviews]"]'));
        self::assertCount(0, $form->filter('input[name="'.self::FORM.'[postWidgetReviews]"]:checked'));
        self::assertCount(1, $form->filter('input[name="'.self::FORM.'[siteReviewCheck]"]'));
        self::assertCount(0, $form->filter('input[name="'.self::FORM.'[siteReviewCheck]"]:checked'));
        self::assertSelectorTextContains('[data-board-automation-settings]', 'Contents: read and write');
        self::assertSelectorNotExists('[data-fix-run-comment-failure]');
        self::assertNull($this->stored($project));

        $submit = $form->form();
        $enabled = $submit[self::FORM.'[enabled]'];
        self::assertInstanceOf(ChoiceFormField::class, $enabled);
        $enabled->untick();
        $commentOnFixQueued = $submit[self::FORM.'[commentOnFixQueued]'];
        self::assertInstanceOf(ChoiceFormField::class, $commentOnFixQueued);
        $commentOnFixQueued->tick();
        $commentOnStaleApproval = $submit[self::FORM.'[commentOnStaleApproval]'];
        self::assertInstanceOf(ChoiceFormField::class, $commentOnStaleApproval);
        $commentOnStaleApproval->tick();
        $syncBehind = $submit[self::FORM.'[syncBehind]'];
        self::assertInstanceOf(ChoiceFormField::class, $syncBehind);
        $syncBehind->tick();
        $mergePullRequests = $submit[self::FORM.'[mergePullRequests]'];
        self::assertInstanceOf(ChoiceFormField::class, $mergePullRequests);
        $mergePullRequests->tick();
        $epicDraftSwitch = $submit[self::FORM.'[epicDraftSwitch]'];
        self::assertInstanceOf(ChoiceFormField::class, $epicDraftSwitch);
        $epicDraftSwitch->tick();
        $openEpicPullRequests = $submit[self::FORM.'[openEpicPullRequests]'];
        self::assertInstanceOf(ChoiceFormField::class, $openEpicPullRequests);
        $openEpicPullRequests->tick();
        $postWidgetReviews = $submit[self::FORM.'[postWidgetReviews]'];
        self::assertInstanceOf(ChoiceFormField::class, $postWidgetReviews);
        $postWidgetReviews->tick();
        $siteReviewCheck = $submit[self::FORM.'[siteReviewCheck]'];
        self::assertInstanceOf(ChoiceFormField::class, $siteReviewCheck);
        $siteReviewCheck->tick();
        $this->client->submit($submit);

        $url = '/projects/'.$project->id.'/settings/automation';
        self::assertResponseRedirects($url);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Automation settings saved.');

        $settings = $this->stored($project);
        self::assertNotNull($settings);
        self::assertTrue($settings->postWidgetReviews);
        self::assertTrue($settings->siteReviewCheck);
        self::assertFalse($settings->enabled);
        self::assertTrue($settings->commentOnFixQueued);
        self::assertTrue($settings->commentOnStaleApproval);
        self::assertTrue($settings->syncBehind);
        self::assertTrue($settings->mergePullRequests);
        self::assertFalse($settings->changeBase);
        self::assertTrue($settings->epicDraftSwitch);
        self::assertFalse($settings->closeEpicPullRequests);
        self::assertTrue($settings->openEpicPullRequests);

        $form = $this->page($project)->filter('form[name="'.self::FORM.'"]');
        self::assertCount(0, $form->filter('input[name="'.self::FORM.'[enabled]"]:checked'));
        self::assertCount(1, $form->filter('input[name="'.self::FORM.'[commentOnFixQueued]"]:checked'));
        self::assertCount(1, $form->filter('input[name="'.self::FORM.'[commentOnStaleApproval]"]:checked'));
        self::assertCount(1, $form->filter('input[name="'.self::FORM.'[syncBehind]"]:checked'));
        self::assertCount(1, $form->filter('input[name="'.self::FORM.'[mergePullRequests]"]:checked'));
        self::assertCount(0, $form->filter('input[name="'.self::FORM.'[changeBase]"]:checked'));
        self::assertCount(1, $form->filter('input[name="'.self::FORM.'[epicDraftSwitch]"]:checked'));
        self::assertCount(1, $form->filter('input[name="'.self::FORM.'[openEpicPullRequests]"]:checked'));
        self::assertCount(1, $form->filter('input[name="'.self::FORM.'[postWidgetReviews]"]:checked'));
        self::assertCount(1, $form->filter('input[name="'.self::FORM.'[siteReviewCheck]"]:checked'));
        self::assertCount(0, $form->filter('input[name="'.self::FORM.'[epicBranchPattern]"]'));
    }

    public function test_the_newest_failed_comment_shows_its_pull_request_and_its_cause(): void
    {
        $project = $this->ownedProject('automation-comment-failed@example.com');
        $this->commentOnFixQueued($project, true);
        $this->settledComment($project, PullRequestCommentState::Posted, 'Acme/Posted', 6, null, new \DateTimeImmutable('-3 hours'));
        $this->settledComment($project, PullRequestCommentState::Failed, 'Acme/Old', 4, 'api_failed_http_status_502', new \DateTimeImmutable('-2 hours'));
        $this->settledComment($project, PullRequestCommentState::Failed, 'Acme/Widgets', 5, 'permission', new \DateTimeImmutable('-5 minutes'));
        $this->em->persist(new PullRequestComment($project, Uuid::v7(), Uuid::v7(), 'github', 'Acme/Pending', 7, null, 'conflict'));
        $this->em->flush();

        $note = $this->page($project)->filter('[data-fix-run-comment-failure]');

        self::assertCount(1, $note);
        self::assertStringContainsString('Acme/Widgets#5', $note->text());
        self::assertStringContainsString('The GitHub App cannot comment. Grant it Pull requests: read and write on GitHub.', $note->text());
        self::assertCount(1, $note->filter('time[datetime]'));
    }

    public function test_a_comment_that_posts_after_the_failure_hides_it(): void
    {
        $project = $this->ownedProject('automation-comment-recovered@example.com');
        $this->commentOnFixQueued($project, true);
        $this->settledComment($project, PullRequestCommentState::Failed, 'Acme/Widgets', 5, 'permission', new \DateTimeImmutable('-2 hours'));
        $this->settledComment($project, PullRequestCommentState::Posted, 'Acme/Widgets', 6, null, new \DateTimeImmutable('-5 minutes'));
        $this->em->flush();

        self::assertCount(1, $this->page($project)->filter('input[name="'.self::FORM.'[commentOnFixQueued]"]:checked'));
        self::assertSelectorNotExists('[data-fix-run-comment-failure]');
    }

    public function test_the_failure_hides_while_the_setting_is_off(): void
    {
        $project = $this->ownedProject('automation-comment-off@example.com');
        $this->commentOnFixQueued($project, false);
        $this->settledComment($project, PullRequestCommentState::Failed, 'Acme/Widgets', 5, 'permission', new \DateTimeImmutable('-5 minutes'));
        $this->em->flush();

        self::assertCount(1, $this->page($project)->filter('input[name="'.self::FORM.'[commentOnFixQueued]"]'));
        self::assertSelectorNotExists('[data-fix-run-comment-failure]');
    }

    public function test_a_suspended_installation_names_the_suspension(): void
    {
        $project = $this->ownedProject('automation-comment-suspended@example.com');
        $this->commentOnFixQueued($project, true);
        $this->settledComment($project, PullRequestCommentState::Failed, 'Acme/Widgets', 5, 'installation_suspended', new \DateTimeImmutable('-5 minutes'));
        $this->em->flush();

        $note = $this->page($project)->filter('[data-fix-run-comment-failure]');

        self::assertStringContainsString('The GitHub App installation is suspended on GitHub.', $note->text());
    }

    public function test_an_incomplete_lookup_says_the_comment_was_not_posted_again(): void
    {
        $project = $this->ownedProject('automation-comment-lookup@example.com');
        $this->commentOnFixQueued($project, true);
        $this->settledComment($project, PullRequestCommentState::Failed, 'Acme/Widgets', 5, 'lookup_incomplete', new \DateTimeImmutable('-5 minutes'));
        $this->em->flush();

        $note = $this->page($project)->filter('[data-fix-run-comment-failure]');

        self::assertStringContainsString('Loupe could not tell whether the comment was already posted, so it did not post it again.', $note->text());
    }

    public function test_an_unknown_cause_shows_the_raw_cause(): void
    {
        $project = $this->ownedProject('automation-comment-unknown@example.com');
        $this->commentOnFixQueued($project, true);
        $this->settledComment($project, PullRequestCommentState::Failed, 'Acme/Widgets', 5, 'api_failed_http_status_422', new \DateTimeImmutable('-5 minutes'));
        $this->em->flush();

        $note = $this->page($project)->filter('[data-fix-run-comment-failure]');

        self::assertStringContainsString('Loupe could not post the comment (api_failed_http_status_422).', $note->text());
    }

    private function commentOnFixQueued(Project $project, bool $on): void
    {
        $settings = new BoardAutomationSettings($project);
        $settings->commentOnFixQueued = $on;
        $this->em->persist($settings);
    }

    private function settledComment(Project $project, PullRequestCommentState $state, string $repository, int $number, ?string $cause, \DateTimeImmutable $at): void
    {
        $comment = new PullRequestComment($project, Uuid::v7(), Uuid::v7(), 'github', $repository, $number, null, 'checks-failed');
        $comment->state = $state;
        $comment->cause = $cause;
        if (PullRequestCommentState::Failed === $state) {
            $comment->failedAt = $at;
        } else {
            $comment->postedAt = $at;
        }
        $this->em->persist($comment);
    }

    public function test_a_stranger_is_forbidden(): void
    {
        $owner = $this->user($this->em, 'automation-owner@example.com');
        $stranger = $this->user($this->em, 'automation-stranger@example.com');
        $project = $this->project($this->em, $owner);
        $this->em->clear();

        $this->client->loginUser($stranger);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/automation');
        self::assertResponseStatusCodeSame(403);

        $this->client->request(Request::METHOD_POST, '/projects/'.$project->id.'/settings/automation', [
            self::FORM => ['enabled' => '1', 'syncBehind' => '1'],
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->stored($project));
    }

    /** @param non-empty-string $email */
    private function ownedProject(string $email): Project
    {
        $owner = $this->user($this->em, $email);
        $project = $this->project($this->em, $owner);
        $this->client->loginUser($owner);

        return $project;
    }

    private function page(Project $project): Crawler
    {
        $this->em->clear();
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/automation');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function stored(Project $project): ?BoardAutomationSettings
    {
        $this->em->clear();
        $repository = static::getContainer()->get(BoardAutomationSettingsRepository::class);
        self::assertInstanceOf(BoardAutomationSettingsRepository::class, $repository);
        $fresh = $this->em->find(Project::class, $project->id);
        self::assertNotNull($fresh);

        return $repository->findOneByProject($fresh);
    }
}
