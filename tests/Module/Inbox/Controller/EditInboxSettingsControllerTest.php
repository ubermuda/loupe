<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Module\Inbox\Entity\InboxProjectSettings;
use App\Module\Inbox\Messenger\ReconcileCardWaits;
use App\Module\Inbox\Repository\InboxProjectSettingsRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class EditInboxSettingsControllerTest extends WebTestCase
{
    use InboxScenario;

    private const string FORM = 'update_inbox_settings_form';
    private const array SWITCHES = ['documentInReview', 'runBlocked', 'runGaveUp', 'runWaitingForPerson', 'pullRequestReady', 'pullRequestFixStopped', 'cardPaused'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_the_owner_sees_every_switch_on_by_default(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-settings-show');
        $project = $this->inboxProject($this->em, $owner);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, $this->url($project));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.lp-settings-nav__item--active[href$="/inbox/settings"]');
        $form = $crawler->filter('form[name="'.self::FORM.'"]');
        self::assertCount(\count(self::SWITCHES), $form->filter('input[type="checkbox"]'));
        foreach (self::SWITCHES as $switch) {
            self::assertCount(1, $form->filter('input[name="'.self::FORM.'['.$switch.']"]:checked'), $switch);
        }
        self::assertNull($this->stored($project));
    }

    public function test_the_owner_saves_the_switches_and_the_whole_project_is_reconciled(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-settings-save');
        $project = $this->inboxProject($this->em, $owner);
        $this->setInboxFlag(true);
        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, $this->url($project));

        $submit = $crawler->filter('form[name="'.self::FORM.'"]')->form();
        $runGaveUp = $submit[self::FORM.'[runGaveUp]'];
        self::assertInstanceOf(ChoiceFormField::class, $runGaveUp);
        $runGaveUp->untick();
        $pullRequestFixStopped = $submit[self::FORM.'[pullRequestFixStopped]'];
        self::assertInstanceOf(ChoiceFormField::class, $pullRequestFixStopped);
        $pullRequestFixStopped->untick();
        $cardPaused = $submit[self::FORM.'[cardPaused]'];
        self::assertInstanceOf(ChoiceFormField::class, $cardPaused);
        $cardPaused->untick();
        $this->client->submit($submit);

        self::assertResponseRedirects($this->url($project));
        // Read before the next request, which resets the transport.
        $sent = array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $this->transport()->getSent()),
            static fn (object $message): bool => $message instanceof ReconcileCardWaits,
        ));
        self::assertEquals([new ReconcileCardWaits((string) $project->id, null)], $sent);

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Inbox settings saved.');
        self::assertCount(0, $this->client->getCrawler()->filter('input[name="'.self::FORM.'[runGaveUp]"]:checked'));

        $settings = $this->stored($project);
        self::assertNotNull($settings);
        self::assertTrue($settings->documentInReview);
        self::assertTrue($settings->runBlocked);
        self::assertFalse($settings->runGaveUp);
        self::assertTrue($settings->runWaitingForPerson);
        self::assertTrue($settings->pullRequestReady);
        self::assertFalse($settings->pullRequestFixStopped);
        self::assertFalse($settings->cardPaused);
    }

    public function test_a_user_who_does_not_own_the_project_is_forbidden(): void
    {
        $project = $this->inboxProject($this->em, $this->signedUpUser($this->em, 'inbox-settings-owner'));
        $this->setInboxFlag(true);

        $this->client->loginUser($this->signedUpUser($this->em, 'inbox-settings-stranger'));
        $this->client->request(Request::METHOD_GET, $this->url($project));
        self::assertResponseStatusCodeSame(403);

        $this->client->request(Request::METHOD_POST, $this->url($project), [self::FORM => ['runGaveUp' => '1']]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->stored($project));
    }

    public function test_the_page_and_its_links_are_gone_while_the_inbox_is_off(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-settings-off');
        $project = $this->inboxProject($this->em, $owner);
        $this->setInboxFlag(false);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, $this->url($project));
        self::assertResponseStatusCodeSame(404);

        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/edit');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.lp-settings-nav a[href$="/inbox/settings"]'));
    }

    public function test_the_inbox_links_to_its_settings_and_the_settings_nav_lists_them(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-settings-link');
        $project = $this->inboxProject($this->em, $owner);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.lp-needs-you__header-actions a[href="'.$this->url($project).'"]'));

        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/edit');
        self::assertCount(1, $crawler->filter('.lp-settings-nav a[href="'.$this->url($project).'"]'));
    }

    private function url(Project $project): string
    {
        return '/projects/'.$project->id.'/inbox/settings';
    }

    private function transport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function stored(Project $project): ?InboxProjectSettings
    {
        $this->em->clear();
        $repository = static::getContainer()->get(InboxProjectSettingsRepository::class);
        self::assertInstanceOf(InboxProjectSettingsRepository::class, $repository);
        $fresh = $this->em->find(Project::class, $project->id);
        self::assertNotNull($fresh);

        return $repository->findForProject($fresh);
    }
}
