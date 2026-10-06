<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Controller;

use App\Tests\Module\Readiness\ReadinessScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class EditReadinessSettingsControllerTest extends WebTestCase
{
    use ReadinessScenario;

    public function test_the_owner_hides_and_shows_the_guide_again(): void
    {
        $client = static::createClient();
        $owner = $this->user('readiness-settings@example.com');
        $project = $this->project($owner, 'Readiness settings');
        $url = '/projects/'.$project->id.'/settings/readiness';
        $this->em()->clear();
        $client->loginUser($owner);

        $crawler = $client->request(Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.lp-settings-nav__item--active[href="'.$url.'"]');
        self::assertSelectorTextContains('[data-readiness-settings]', 'Discovery has not run on this project.');
        self::assertSame('checked', $crawler->filter('#update_readiness_settings_form_showGuide')->attr('checked'));
        self::assertSelectorNotExists('[data-readiness-hidden-at]');

        $client->submitForm('Save', ['update_readiness_settings_form[showGuide]' => false]);
        self::assertResponseRedirects($url);
        self::assertNotNull($this->reload($project)->readinessGuideHiddenAt);

        $crawler = $client->followRedirect();
        self::assertNull($crawler->filter('#update_readiness_settings_form_showGuide')->attr('checked'));
        self::assertSelectorTextContains('[data-readiness-hidden-at]', 'Hidden on');
        $client->request(Request::METHOD_GET, '/projects/'.$project->id);
        self::assertSelectorNotExists('[data-readiness-guide]');

        $client->request(Request::METHOD_GET, $url);
        $client->submitForm('Save', ['update_readiness_settings_form[showGuide]' => true]);
        self::assertResponseRedirects($url);
        self::assertNull($this->reload($project)->readinessGuideHiddenAt);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id);
        self::assertSelectorExists('[data-readiness-guide]');
    }

    public function test_saving_a_hidden_guide_again_keeps_its_date(): void
    {
        $client = static::createClient();
        $owner = $this->user('readiness-settings-keep@example.com');
        $project = $this->project($owner, 'Readiness keep');
        $hiddenAt = new \DateTimeImmutable('2026-01-02 03:04:05');
        $project->readinessGuideHiddenAt = $hiddenAt;
        $this->em()->flush();
        $url = '/projects/'.$project->id.'/settings/readiness';
        $this->em()->clear();
        $client->loginUser($owner);

        $client->request(Request::METHOD_GET, $url);
        $client->submitForm('Save');

        self::assertResponseRedirects($url);
        self::assertEquals($hiddenAt, $this->reload($project)->readinessGuideHiddenAt);
    }

    public function test_the_settings_nav_links_the_page(): void
    {
        $client = static::createClient();
        $owner = $this->user('readiness-settings-nav@example.com');
        $project = $this->project($owner, 'Readiness nav');
        $this->em()->clear();
        $client->loginUser($owner);

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/edit');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.lp-settings-nav a[href="/projects/'.$project->id.'/settings/readiness"]', 'Agent readiness');
    }

    public function test_a_user_who_does_not_own_the_project_is_refused(): void
    {
        $client = static::createClient();
        $owner = $this->user('readiness-settings-owner@example.com');
        $stranger = $this->user('readiness-settings-stranger@example.com');
        $project = $this->project($owner, 'Readiness private');
        $this->em()->clear();
        $client->loginUser($stranger);

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/readiness');

        self::assertResponseStatusCodeSame(403);
    }
}
